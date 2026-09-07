'use strict';
const targets = [
  {id:'dip-home',name:'縣府數據平台首頁（連線對照）',url:'https://dip.hsinchu.gov.tw/',expected:'html'},
  {id:'dip-news',name:'數據平台最新消息介面（直接呼叫）',url:'https://dip.hsinchu.gov.tw/Home/NewsHomeList',expected:'json'},
  {id:'dip-news-session',name:'數據平台最新消息介面（正常首頁工作階段）',url:'https://dip.hsinchu.gov.tw/Home/NewsHomeList',expected:'json',session:true},
  {id:'county-json',name:'新竹縣鄉鎮市公所 JSON（開放資料檔案）',url:'https://ws.hsinchu.gov.tw/001/Upload/1/opendata/8774/2110/c070f1a4-018b-445e-9ed1-ccec3f30e54c.json',expected:'json',source:'https://data.gov.tw/dataset/162710'},
  {id:'county-home',name:'縣府開放資料專區（連線對照）',url:'https://www.hsinchu.gov.tw/OpenData',expected:'html'}
];
module.exports={targets,allowedHosts:['dip.hsinchu.gov.tw','www.hsinchu.gov.tw','ws.hsinchu.gov.tw']};
