'use strict';
const https=require('node:https');
const dns=require('node:dns').promises;
const net=require('node:net');
const {performance}=require('node:perf_hooks');
const {targets,allowedHosts}=require('./targets');
const MAX_BYTES=1024*1024;
function fail(code,message){return Object.assign(new Error(message),{code});}
function validateUrl(value){
  const url=new URL(value);
  if(url.protocol!=='https:'||url.username||url.password||(url.port&&url.port!=='443')||!allowedHosts.includes(url.hostname))throw fail('TARGET_NOT_ALLOWED','只允許三個指定縣府主機的 HTTPS 443 網址。');
  if(url.href.length>2048)throw fail('URL_TOO_LONG','網址過長。');
  if([...url.searchParams.keys()].some(key=>/token|key|secret|password|authorization/i.test(key)))throw fail('SECRET_IN_URL','此實驗只測公開介面，請勿在網址提供金鑰。');
  url.hash='';return url;
}
function isPublic(address){
  if(net.isIP(address)===4){const [a,b]=address.split('.').map(Number);return !(a===0||a===10||a===127||a>=224||(a===100&&b>=64&&b<=127)||(a===169&&b===254)||(a===172&&b>=16&&b<=31)||(a===192&&(b===168||b===0))||(a===198&&(b===18||b===19)));}
  return net.isIP(address)===6&&/^[23]/i.test(address)&&!address.toLowerCase().startsWith('2001:db8:');
}
function selectTarget(input){
  if(!input||typeof input!=='object'||Array.isArray(input))throw fail('BAD_INPUT','請提供測試設定。');
  let target;
  if(input.targetId){target=targets.find(t=>t.id===input.targetId);if(!target)throw fail('BAD_TARGET','找不到此測試項目。');}
  else if(typeof input.url==='string'){target={id:'custom',name:'自訂公開 JSON 介面',url:validateUrl(input.url).href,expected:'json'};}
  else throw fail('BAD_INPUT','請選擇測試項目。');
  const family=input.family??0;
  if(![0,4,6].includes(family))throw fail('BAD_FAMILY','IP 模式須為 0、4 或 6。');
  return {...target,url:validateUrl(target.url).href,family};
}
function timed(promise,ms){let timer;return Promise.race([promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(fail('TIMEOUT','整體測試超過時間限制。')),Math.max(1,ms));})]).finally(()=>clearTimeout(timer));}
async function requestOnce(url,{deadline,family=0,headers={}}){
  const start=performance.now();
  const detail={url:url.href,dnsMs:null,tcpMs:null,tlsMs:null,firstByteMs:null,totalMs:null,dns:[],remoteAddress:null,status:null,contentType:null,tlsVerified:false};
  try{
    const addresses=await timed(dns.lookup(url.hostname,{all:true,family,verbatim:true}),deadline-Date.now());
    detail.dns=addresses;detail.dnsMs=Math.round(performance.now()-start);
    const eligible=addresses.filter(x=>isPublic(x.address));
    if(!eligible.length)throw fail('NON_PUBLIC_DNS','DNS 未回傳允許的公開位址。');
    // Pin the resolved address: the connection cannot resolve a different address later.
    const chosen=eligible[0];
    return await new Promise((resolve,reject)=>{
      let bytes=0,done=false;const chunks=[];
      const timer=setTimeout(()=>req.destroy(fail('TIMEOUT','連線或下載超過時間限制。')),Math.max(1,deadline-Date.now()));
      const finish=(error,result)=>{if(done)return;done=true;clearTimeout(timer);detail.totalMs=Math.round(performance.now()-start);if(error){error.detail=detail;reject(error);}else resolve({...result,detail});};
      const req=https.request(url,{method:'GET',agent:false,headers:{'User-Agent':'ShinzhuConnectivityProbe/1.0','Accept':'application/json, text/html;q=0.8','Accept-Encoding':'identity',...headers},lookup:(hostname,opts,cb)=>opts.all?cb(null,[chosen]):cb(null,chosen.address,chosen.family)},res=>{
        detail.status=res.statusCode;detail.contentType=res.headers['content-type']||'';detail.firstByteMs=Math.round(performance.now()-start);
        detail.headers=Object.fromEntries(['content-type','content-length','server','date','retry-after','access-control-allow-origin'].filter(k=>res.headers[k]!==undefined).map(k=>[k,res.headers[k]]));
        res.on('data',chunk=>{bytes+=chunk.length;if(bytes>MAX_BYTES){finish(null,{body:Buffer.concat(chunks).toString('utf8'),bytes,truncated:true,headers:res.headers});res.destroy();req.destroy();}else chunks.push(chunk);});
        res.on('end',()=>finish(null,{body:Buffer.concat(chunks).toString('utf8'),bytes,truncated:false,headers:res.headers}));
        res.on('error',err=>finish(err));
        res.on('aborted',()=>finish(fail('RESPONSE_ABORTED','上游回應未完整傳送。')));
      });
      req.on('socket',socket=>{socket.on('connect',()=>{detail.tcpMs=Math.round(performance.now()-start)-detail.dnsMs;detail.remoteAddress=socket.remoteAddress;});socket.on('secureConnect',()=>{detail.tlsMs=Math.round(performance.now()-start)-detail.dnsMs-detail.tcpMs;detail.tlsVerified=socket.authorized;detail.tlsProtocol=socket.getProtocol();});});
      req.on('error',err=>finish(err));req.end();
    });
  }catch(error){error.detail=error.detail||detail;throw error;}
}
function redact(text,secrets=[]){let value=String(text);for(const secret of secrets)if(secret)value=value.split(secret).join('[redacted]');return value.replace(/(<input\b[^>]*(?:anti.?forgery|csrf)[^>]*\bvalue=["'])[^"']*/gi,'$1[redacted]');}
function classify(response,expected){
  const body=response.body.replace(/^\uFEFF/,'').trim();let json=null,jsonValid=false;
  if(!response.truncated){try{json=JSON.parse(body);jsonValid=true;}catch{}}
  const status=response.detail.status;const httpOk=status>=200&&status<300;
  const htmlLike=/^(?:<!doctype|<html|<input|<script|<head)/i.test(body)||/text\/html/i.test(response.detail.contentType);
  let outcome=httpOk?'reachable':'http_error';
  if(response.truncated)outcome='body_limit';
  else if(httpOk&&expected==='json')outcome=jsonValid?'json_ok':htmlLike?'html_instead_of_json':'invalid_json';
  return {outcome,httpOk,jsonValid,htmlLike,recordCount:Array.isArray(json)?json.length:null,topLevelKeys:json&&typeof json==='object'&&!Array.isArray(json)?Object.keys(json).slice(0,20):null,applicationSuccess:json&&typeof json==='object'&&!Array.isArray(json)&&typeof json.success==='boolean'?json.success:null};
}
async function runProbe(target,{transport=requestOnce,timeoutMs=20000}={}){
  const start=Date.now(),deadline=start+timeoutMs,hops=[],secrets=[];let response;
  async function follow(initial,headers={},phase='target'){
    let url=validateUrl(initial);
    for(let redirect=0;redirect<=3;redirect++){
      let r;try{r=await transport(url,{deadline,family:target.family,headers});}catch(error){if(error.detail)hops.push({phase,...error.detail});throw error;}
      hops.push({phase,...r.detail});
      if([301,302,303,307,308].includes(r.detail.status)&&r.headers.location){
        if(redirect===3)throw fail('REDIRECT_LIMIT','重新導向超過 3 次。');
        const next=validateUrl(new URL(r.headers.location,url).href);
        hops[hops.length-1].redirectTo=next.href;
        // Never forward session cookies or tokens to another origin.
        if(next.origin!==url.origin)headers={};url=next;continue;
      }
      return r;
    }
  }
  try{
    let headers={};
    if(target.session){
      const seed=await follow('https://dip.hsinchu.gov.tw/',{},'session');
      if(seed.detail.status<200||seed.detail.status>=300||seed.truncated)throw fail('SESSION_INIT_FAILED','首頁工作階段未成功取得。');
      const input=seed.body.match(/<input\b[^>]*name=["']AntiforgeryField["'][^>]*>/i)?.[0];
      const token=input?.match(/value=["']([^"']+)["']/i)?.[1];
      if(!token)throw fail('SESSION_TOKEN_MISSING','首頁未找到公開頁面使用的防偽欄位；請檢查平台是否改版。');
      const rawCookies=seed.headers['set-cookie']||[];
      const cookies=(Array.isArray(rawCookies)?rawCookies:[rawCookies]).map(x=>x.split(';')[0]);
      secrets.push(token,...cookies.map(c=>c.slice(c.indexOf('=')+1)));
      headers={'X-XSRF-TOKEN':token,'Cookie':cookies.join('; '),'Referer':'https://dip.hsinchu.gov.tw/'};
    }
    response=await follow(target.url,headers);
    const classification=classify(response,target.expected);
    return {targetId:target.id,target:target.name,url:target.url,expected:target.expected,family:target.family,session:!!target.session,startedAt:new Date(start).toISOString(),durationMs:Date.now()-start,hops,...classification,bytes:response.bytes,truncated:response.truncated,preview:redact(response.body,secrets).slice(0,3000),note:'JSON 可解析不等於資料內容、授權或查詢結果已通過業務驗證；本次結果也不能代表其他 API。'};
  }catch(error){
    const code=error.code||'REQUEST_FAILED';
    const outcome=code==='TIMEOUT'?'timeout':['ENOTFOUND','EAI_AGAIN','ENODATA'].includes(code)?'dns_error':/CERT|TLS|SSL/.test(code)?'tls_error':/TARGET|PUBLIC|URL|REDIRECT/.test(code)?'blocked_target':'network_or_session_error';
    return {targetId:target.id,target:target.name,url:target.url,family:target.family,session:!!target.session,startedAt:new Date(start).toISOString(),durationMs:Date.now()-start,hops,outcome,httpOk:false,jsonValid:false,error:{code,message:redact(error.message,secrets)},note:'此錯誤本身不能證明海外封鎖；需比較同一網址在本機與 Hostinger 的結果。'};
  }
}
module.exports={selectTarget,validateUrl,isPublic,classify,runProbe,requestOnce};
