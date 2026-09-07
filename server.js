'use strict';
const http=require('node:http');
const fs=require('node:fs');
const path=require('node:path');
const crypto=require('node:crypto');
const {targets}=require('./api-probe/targets');
const {selectTarget,runProbe}=require('./api-probe/probe');
const bootId=crypto.randomUUID();
function createApp({probe=runProbe,token=process.env.PROBE_TOKEN||'',cooldownMs=1000}={}){
  let active=false,lastStarted=-Infinity;
  const server=http.createServer(async(req,res)=>{
    res.setHeader('Cache-Control','no-store');res.setHeader('X-Content-Type-Options','nosniff');res.setHeader('Referrer-Policy','no-referrer');
    res.setHeader('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'");
    function json(status,data){res.writeHead(status,{'Content-Type':'application/json; charset=utf-8'});res.end(JSON.stringify(data));}
    const pathname=new URL(req.url,'http://localhost').pathname;
    if(req.method==='GET'&&pathname==='/api/health')return json(200,{ok:true,service:'shinzhu-api-probe',execution:'server',deploymentLabel:process.env.DEPLOYMENT_LABEL||'未設定（請自行確認部署環境）',node:process.version,platform:process.platform,bootId,serverTime:new Date().toISOString(),tokenRequired:!!token});
    if(req.method==='GET'&&pathname==='/api/targets')return json(200,{targets});
    if(req.method==='POST'&&pathname==='/api/probe'){
      if(token){const supplied=Buffer.from(req.headers['x-probe-token']||'');const expected=Buffer.from(token);if(supplied.length!==expected.length||!crypto.timingSafeEqual(supplied,expected))return json(401,{error:'測試密碼不正確。'});}
      if(active||Date.now()-lastStarted<cooldownMs)return json(429,{error:'已有測試執行中，或呼叫太頻繁。請稍候再試。'});
      active=true;
      try{
        let data='',size=0;
        for await(const chunk of req){size+=chunk.length;if(size>4096){json(413,{error:'請求內容過大。'});return;}data+=chunk;}
        let target;try{target=selectTarget(JSON.parse(data));}catch(err){return json(400,{error:err.message});}
        lastStarted=Date.now();const result=await probe(target);
        return json(200,{...result,execution:'server',deploymentLabel:process.env.DEPLOYMENT_LABEL||'未設定',bootId,serverTime:new Date().toISOString()});
      }catch(error){if(!res.headersSent)json(500,{error:'測試服務發生錯誤。',code:error.code||'SERVER_ERROR'});}
      finally{active=false;}
      return;
    }
    const files={'/':['index.html','text/html; charset=utf-8'],'/client.js':['client.js','text/javascript; charset=utf-8'],'/style.css':['style.css','text/css; charset=utf-8']};
    if(req.method==='GET'&&files[pathname]){const [file,type]=files[pathname];res.writeHead(200,{'Content-Type':type});fs.createReadStream(path.join(__dirname,'api-probe/public',file)).pipe(res);return;}
    json(404,{error:'Not found'});
  });
  server.requestTimeout=30000;server.headersTimeout=10000;return server;
}
if(require.main===module){const port=Number(process.env.PORT)||3000;const server=createApp();server.listen(port,'0.0.0.0',()=>console.log(`API probe listening on port ${port}`));process.on('SIGTERM',()=>server.close());}
module.exports={createApp};
