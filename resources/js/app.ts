import { createApp, ref } from 'vue';
import '../css/app.css';

const App = {
    setup() {
        const status = ref('Laravel + Vue 基礎已就緒');
        const checked = ref(false);

        function acknowledge(): void {
            checked.value = true;
            status.value = '學院入口已確認，下一階段接入資料與關卡引擎。';
        }

        return { status, checked, acknowledge };
    },
    template: `
        <main class="academy-shell">
            <div class="academy-mark">惡</div>
            <p class="eyebrow">HSINCHU VILLAIN ACADEMY · PHASE 01</p>
            <h1>超認真毀滅新竹計畫</h1>
            <p class="lead">Laravel 13、Vue 3、SQLite 的新版學院入口已建立。真正的 13 關戰役會在後續階段接入。</p>
            <div class="status-card" role="status" aria-live="polite"><span class="status-dot" :class="{ ready: checked }"></span><span>{{ status }}</span></div>
            <button type="button" class="academy-button" @click="acknowledge">{{ checked ? '等待下一階段 ↗' : '確認入學 ↗' }}</button>
            <p class="note">目前頁面只驗證新版框架殼；GitHub Pages 上的靜態 MVP 保留給初階文件審查。</p>
        </main>
    `,
};

createApp(App).mount('#app');
