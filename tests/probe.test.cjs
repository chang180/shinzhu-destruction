const {test}=require('node:test');
const assert=require('node:assert/strict');
const {selectTarget,validateUrl,isPublic,classify,runProbe}=require('../api-probe/probe');
const {createApp}=require('../server');
function response(url,body,status=200,headers={}){return {body,bytes:Buffer.byteLength(body),truncated:false,headers,detail:{url:url.href,status,contentType:headers['content-type']||'application/json'}};}
test('only expected HTTPS hosts and public addresses are accepted',()=>{
  for(const url of ['http://dip.hsinchu.gov.tw/','https://dip.hsinchu.gov.tw.evil.test/','https://127.0.0.1/','https://dip.hsinchu.gov.tw:8443/','https://user:pass@dip.hsinchu.gov.tw/','https://dip.hsinchu.gov.tw/?api_key=secret'])assert.throws(()=>validateUrl(url));
  for(const ip of ['127.0.0.1','10.0.0.1','169.254.169.254','172.16.0.1','192.168.0.1','::1','::ffff:127.0.0.1','fe80::1'])assert.equal(isPublic(ip),false);
  assert.equal(isPublic('8.8.8.8'),true);assert.throws(()=>selectTarget({targetId:'missing'}));assert.throws(()=>selectTarget({targetId:'dip-news',family:5}));
});
test('HTTP 200 HTML is not API success; BOM JSON is valid; 403 remains failure',()=>{
  assert.equal(classify(response(new URL('https://dip.hsinchu.gov.tw/'),'<!doctype html><html>login</html>'), 'json').outcome,'html_instead_of_json');
  assert.equal(classify(response(new URL('https://dip.hsinchu.gov.tw/'),'\ufeff[{"x":1}]'),'json').recordCount,1);
  assert.equal(classify(response(new URL('https://dip.hsinchu.gov.tw/'),'{}',403),'json').outcome,'http_error');
  assert.equal(classify({...response(new URL('https://dip.hsinchu.gov.tw/'),'[]'),truncated:true},'json').jsonValid,false);
});
test('redirect to foreign host is stopped before outgoing request',async()=>{
  let calls=0;const result=await runProbe(selectTarget({targetId:'dip-news'}),{transport:async url=>{calls++;return response(url,'',302,{location:'https://example.com/'});}});
  assert.equal(calls,1);assert.equal(result.outcome,'blocked_target');
});
test('normal session supplies token and cookie but never exports secrets',async()=>{
  let calls=0;const result=await runProbe(selectTarget({targetId:'dip-news-session'}),{transport:async(url,options)=>{
    calls++;if(calls===1)return response(url,'<input name="AntiforgeryField" value="my-private-token">',200,{'set-cookie':['session=my-private-cookie; HttpOnly']});
    assert.equal(options.headers['X-XSRF-TOKEN'],'my-private-token');assert.equal(options.headers.Cookie,'session=my-private-cookie');return response(url,'{"echo":"my-private-token my-private-cookie"}');
  }});
  assert.equal(calls,2);assert.equal(result.outcome,'json_ok');assert.ok(!JSON.stringify(result).includes('my-private'));assert.ok(result.preview.includes('[redacted]'));
});
test('cross-origin redirect removes session headers',async()=>{
  let calls=0;await runProbe(selectTarget({targetId:'dip-news-session'}),{transport:async(url,options)=>{
    calls++;if(calls===1)return response(url,'<input name="AntiforgeryField" value="secret">',200,{'set-cookie':['session=cookie; HttpOnly']});
    if(calls===2)return response(url,'',302,{location:'https://www.hsinchu.gov.tw/'});
    assert.deepEqual(options.headers,{});return response(url,'[]');
  }});assert.equal(calls,3);
});
test('network errors keep diagnostic stage and do not claim country blocking',async()=>{
  const result=await runProbe(selectTarget({targetId:'dip-news'}),{transport:async()=>{throw Object.assign(new Error('lookup failed'),{code:'ENOTFOUND',detail:{dns:[]}});}});
  assert.equal(result.outcome,'dns_error');assert.equal(result.hops.length,1);assert.equal(result.httpOk,false);
});
test('timeout is distinct from an HTTP error',async()=>{
  const result=await runProbe(selectTarget({targetId:'dip-news'}),{transport:async()=>{throw Object.assign(new Error('deadline'),{code:'TIMEOUT'});}});
  assert.equal(result.outcome,'timeout');assert.equal(result.httpOk,false);
});
test('a second concurrent probe is rejected without another upstream call',async t=>{
  let complete;const pending=new Promise(r=>{complete=r;});let started;const signal=new Promise(r=>{started=r;});
  const server=createApp({cooldownMs:0,probe:async()=>{started();await pending;return {outcome:'json_ok'};}});await new Promise(r=>server.listen(0,'127.0.0.1',r));t.after(()=>new Promise(r=>server.close(r)));
  const endpoint='http://127.0.0.1:'+server.address().port+'/api/probe';const options={method:'POST',body:JSON.stringify({targetId:'dip-news'})};
  const first=fetch(endpoint,options);await signal;const second=await fetch(endpoint,options);assert.equal(second.status,429);complete();assert.equal((await first).status,200);
});
test('HTTP app health, authorization, invalid target, execution and file isolation',async t=>{
  let calls=0;const server=createApp({token:'test-only',cooldownMs:0,probe:async target=>{calls++;return {target:target.id,outcome:'json_ok'};}});await new Promise(r=>server.listen(0,'127.0.0.1',r));t.after(()=>new Promise(r=>server.close(r)));
  const base='http://127.0.0.1:'+server.address().port;
  assert.equal((await fetch(base+'/api/health').then(r=>r.json())).execution,'server');
  assert.equal((await fetch(base+'/package.json')).status,404);
  const post=(data,token='test-only')=>fetch(base+'/api/probe',{method:'POST',headers:{'Content-Type':'application/json','X-Probe-Token':token},body:JSON.stringify(data)});
  assert.equal((await post({targetId:'dip-news'},'wrong')).status,401);
  assert.equal((await post({url:'https://127.0.0.1/'})).status,400);
  const result=await post({targetId:'dip-news'}).then(r=>r.json());assert.equal(result.outcome,'json_ok');assert.equal(result.execution,'server');assert.equal(calls,1);
});
