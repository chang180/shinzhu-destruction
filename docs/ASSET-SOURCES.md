# 免費開放素材來源與下載紀錄

更新：2026-09-08。使用者已同意在搜尋階段下載適合的免費開源／開放授權素材；本次已取得 **4 張特效圖、4 個音效** 作為後續製作候選，保留兩份原始授權與逐檔 hash。檔案及紀錄合計約 **277 KiB**，未修改現有遊戲或接入正式演出。

## 已下載

| 素材 | 作者／版本 | 官方來源與授權 | 已保留的用途 |
|---|---|---|---|
| Particle Pack | Kenney；壓縮包授權標示 1.1，網頁更新欄標示初始 1.0，版本差異如實保留 | [作品頁](https://kenney.nl/assets/particle-pack)，CC0；[包內授權](../assets/third-party/kenney-particle-pack/License.txt) | 護盾環、煙霧、電弧、蓄力光紋 |
| Impact Sounds | Kenney；1.0 | [作品頁](https://kenney.nl/assets/impact-sounds)，CC0；[包內授權](../assets/third-party/kenney-impact-sounds/License.txt) | 輕命中、金屬重擊、玻璃破裂、鐘響 |

官方資產頁明列 CC0；下載包內授權亦已讀取一致。署名仍保留 Kenney 與包內原作者資訊，完整權利文字以包內文件及 [CC0 原文](https://creativecommons.org/publicdomain/zero/1.0/)為準。

### 實際檔案

| 檔案 | 候選 cue | 本次檢查 |
|---|---|---|
| [circle_01.png](../assets/third-party/kenney-particle-pack/circle_01.png) | 護盾吸收／範圍波紋 | 已開圖檢視，抽象環形紋理 |
| [smoke_03.png](../assets/third-party/kenney-particle-pack/smoke_03.png) | 城市受擊／土地系煙塵 | 已開圖檢視，抽象煙霧 |
| [spark_07.png](../assets/third-party/kenney-particle-pack/spark_07.png) | 連攜／打斷電弧 | 已開圖檢視，水平電弧 |
| [light_01.png](../assets/third-party/kenney-particle-pack/light_01.png) | 終招蓄力／核心波動 | 已開圖檢視，抽象光紋 |
| [impactGeneric_light_000.ogg](../assets/third-party/kenney-impact-sounds/impactGeneric_light_000.ogg) | 普通命中 | 已下載；音色、音量及瀏覽器播放待驗 |
| [impactMetal_heavy_000.ogg](../assets/third-party/kenney-impact-sounds/impactMetal_heavy_000.ogg) | 護甲／重擊 | 已下載；音色、音量及瀏覽器播放待驗 |
| [impactGlass_heavy_000.ogg](../assets/third-party/kenney-impact-sounds/impactGlass_heavy_000.ogg) | 防線破裂 | 已下載；音色、音量及瀏覽器播放待驗 |
| [impactBell_heavy_000.ogg](../assets/third-party/kenney-impact-sounds/impactBell_heavy_000.ogg) | 學院／結算提示 | 已下載；音色、音量及瀏覽器播放待驗 |

這些是一般遊戲特效與音效，沒有把任何動漫同人作品標成已獲授權。主視覺、角色與 13 使徒仍依[美術音效規格](ART-AUDIO-SPEC.md)原創生成。

## 後續可評估，尚未下載

| 候選 | 來源 | 適合用途／採用條件 |
|---|---|---|
| Kenney City Kit (Industrial) | [官方作品頁](https://kenney.nl/assets/city-kit-industrial)，頁面標示 CC0 | 園區、能源與城市場景構圖基礎；若採用，下載後再核對包內授權，以製作階段轉成 2D 為優先 |
| 原創超能力／反派學院致敬作品 | 尚未選定作品，不列為已取得 | P04 可繼續找作者直接釋出的開放授權作品；核對圖像與程式分別的授權、角色／標誌來源及視覺一致性後才採用 |

## 交接與正式採用

- [來源 manifest](../assets/third-party/manifest.json)記錄原包 URL、原包 SHA-256、下載日期、保留檔案在包內的路徑、大小及逐檔 SHA-256。
- 本次只把小型候選子集放入 repo，原下載壓縮包留於工作用暫存區，正式工作不依賴那個暫存路徑；可由 manifest 重新取得並核對。原檔未改色、裁切或轉碼。
- P04 先試接入代表性 cue；P06 完成風格、音量、手機相容及效能驗收，才把轉檔後版本加入正式資產 manifest。來源檔不用全部公開給每個遊戲訪客。
- 圖像已做基本目視檢查，尚未完成遊戲中構圖與混色驗收；音效尚未試聽。所有素材目前都未整合，不把此次下載記為 P04／P06 已完成。
