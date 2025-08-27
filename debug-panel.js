// 安全なグローバルエクスポート
(function() {
  try {
    const debugInstance = wpCrossPostDebug();
    window.wpCrossPostDebug = debugInstance;
  } catch (e) {
    console.error('デバッグシステム初期化エラー:', e);
    window.wpCrossPostDebug = {
      logs: [],
      log: (msg) => console.log('[FALLBACK]', msg)
    };
  }
})();

// 安全な初期化チェック
const ensureDebugSystem = () => {
  try {
    return window.wpCrossPostDebug || initializeFallbackDebug();
  } catch (e) {
    console.error('デバッグシステムアクセスエラー:', e);
    return initializeFallbackDebug();
  }
};

function initializeFallbackDebug() {
  return {
    logs: [],
    log: (msg) => console.log('[FALLBACK]', msg),
    error: (msg) => console.error('[FALLBACK]', msg)
  };
}

// 初期化タイミングの調整
document.addEventListener('DOMContentLoaded', function() {
  try {
    if (!window.wpCrossPostDebug) {
      window.wpCrossPostDebug = initializeNamespace();
    }
  } catch (e) {
    console.error('デバッグシステム再初期化失敗:', e);
  }
});

// デバッグシステム強化
(() => {
    const MAX_LOG_SIZE = 1000;
    const debugSystem = {
        logs: [],
        log: function(message, type = 'info') {
            this.logs.push({
                timestamp: new Date().toISOString(),
                message,
                type
            });
            if(this.logs.length > MAX_LOG_SIZE) this.logs.shift();
        },
        getRecent: function(count = 50) {
            return this.logs.slice(-count);
        },
        healthCheck: function() {
            return fetch('/wp-json/wp-cross-post/v1/health')
                .then(res => res.json())
                .catch(() => ({ status: 'unhealthy' }));
        }
    };

    // グローバル公開
    window.wpCrossPostDebug = debugSystem;

    // 定期的なヘルスチェック
    setInterval(() => {
        debugSystem.healthCheck().then(status => {
            debugSystem.log(`Health Check: ${status}`);
        });
    }, 30000);
})(); 