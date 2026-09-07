(function (root) {
  'use strict';
  const skills = [
    { id: 'water', symbol: '≈', tag: '水系 / SDG 6', name: '滄海吞星・無限乾渴', sub: '據說能吞掉整片海。今天先吸一口。', damage: 1, label: '渴', defense: '水資源管理', lesson: '需求管理與水資源調度是理解供水韌性的起點；單一水庫蓄水率不能代表整個地區的供水狀態。' },
    { id: 'heat', symbol: '☼', tag: '熱系 / SDG 11・13', name: '九重煉獄・竹北氣炸鍋', sub: '火力全開！但對方好像有種樹。', damage: 2, label: '熱', defense: '綠地與遮蔭', lesson: '綠地、遮蔭與都市設計值得共同討論；具體降溫效果仍需場域量測與模型驗證。' },
    { id: 'land', symbol: '◇', tag: '土地系 / SDG 15', name: '萬象石化・鋼筋王座', sub: '將大地化為王座。先從一塊磁磚開始。', damage: 1, label: '土', defense: '土地保留與公民協作', lesson: '土地配置牽涉居住、產業與生態的取捨；保留面積與生態連通性需要不同資料來評估。' }
  ];
  function initial() { return { hp:100, turn:0, damage:0, repair:0, marks:{water:0,heat:0,land:0}, history:[], counts:{water:0,heat:0,land:0}, ended:false, won:false }; }
  function act(state,id) {
    if(state.ended || !['water','heat','land','ultimate'].includes(id)) return null;
    const s=JSON.parse(JSON.stringify(state)); s.turn++;
    let hit,combo=false,charged=false;
    if(id==='ultimate') { charged=Object.values(s.marks).every(n=>n===3); hit=charged?108:4; s.marks={water:0,heat:0,land:0}; s.history=[]; }
    else { const skill=skills.find(x=>x.id===id); hit=skill.damage; s.counts[id]++; s.marks[id]=Math.min(3,s.marks[id]+1); s.history.push(id); combo=s.history.length>=3 && new Set(s.history.slice(-3)).size===3; if(combo) hit+=3; }
    const dealt=Math.min(s.hp,hit); s.hp-=dealt; s.damage+=dealt;
    let healed=0;
    if(s.hp>0 && s.turn%3===0) { healed=Math.min(3,100-s.hp); s.hp+=healed;s.repair+=healed; }
    s.won=s.hp===0; s.ended=s.won||s.turn>=12;
    return {state:s,hit:dealt,healed,combo,charged,id};
  }
  const api={skills,initial,act}; if(typeof module!=='undefined') module.exports=api; else root.VillainGame=api;
})(typeof window!=='undefined'?window:globalThis);
