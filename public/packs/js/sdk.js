(function(){
  var DEBUG = true;
  function log(msg) {
    if (DEBUG) console.log('[GrowHub SDK] ' + msg);
  }

  function detectBaseUrl() {
    try {
      var scripts = document.getElementsByTagName('script');
      for (var i = 0; i < scripts.length; i++) {
        var src = scripts[i].src || '';
        if (src && src.indexOf('/packs/js/sdk.js') !== -1) {
          var u = new URL(src);
          return u.origin;
        }
      }
      return (window.location && window.location.origin) || '';
    } catch (e) {
      log('Error detecting base URL: ' + e);
      return (window.location && window.location.origin) || '';
    }
  }

  var api = {
    run: function({ websiteToken, baseUrl }) {
      try {
        if (window.growhubSDK_running) {
          log('SDK is already running');
          return;
        }
        window.growhubSDK_running = true;

        log('Run called with token: ' + websiteToken);
        baseUrl = (baseUrl || detectBaseUrl() || '').replace(/\/$/, '');
        log('Base URL: ' + baseUrl);
        
        if (!websiteToken) {
          console.error('[GrowHub SDK] Website token is required');
          return;
        }

        api.initIframe({ websiteToken, baseUrl });
      } catch (e) {
        console.error('[GrowHub SDK] Error in run:', e);
      }
    },

    initIframe: function({ websiteToken, baseUrl }) {
      if (document.getElementById('grow-widget-container')) {
        log('Widget container already exists');
        return;
      }

      var widgetUrl = `${baseUrl}/widget?website_token=${websiteToken}`;
      log('Loading widget from: ' + widgetUrl);

      var container = document.createElement('div');
      container.id = 'grow-widget-container';
      
      var iframe = document.createElement('iframe');
      iframe.id = 'grow-widget-iframe';
      iframe.src = widgetUrl;
      iframe.style.border = 'none';
      iframe.style.width = '100%';
      iframe.style.height = '100%';
      iframe.style.position = 'absolute';
      iframe.style.top = '0';
      iframe.style.left = '0';
      
      // Container styles
      container.style.position = 'fixed';
      container.style.bottom = '20px';
      container.style.right = '20px';
      container.style.width = '100px'; 
      container.style.height = '100px';
      container.style.zIndex = '2147483647';
      container.style.transition = 'width 0.3s ease, height 0.3s ease, bottom 0.3s ease, right 0.3s ease';
      container.style.boxShadow = 'none';
      container.style.borderRadius = '0';
      container.style.overflow = 'visible'; 
      
      container.appendChild(iframe);
      document.body.appendChild(container);
      log('Widget container appended to body');

      iframe.onload = function() {
        log('Iframe loaded (onload fired)');
      };
      iframe.onerror = function() {
        log('Iframe failed to load (onerror fired)');
      };

      // Listen for messages from iframe
      window.addEventListener('message', function(event) {
        // We don't strictly check origin here because we want to allow
        // the widget to work even if the SDK is on a different domain/protocol (e.g. file:// vs http://)
        // and the widget itself is sending '*' as targetOrigin.
        
        var data = event.data;
        if (data && data.type === 'grow-widget:toggle') {
          log('Toggle event received: ' + data.isOpen);
          if (data.isOpen) {
            // Expand
            container.style.width = '380px';
            container.style.height = '600px';
            container.style.bottom = '20px';
            container.style.right = '20px';
            container.style.boxShadow = '0 5px 40px rgba(0,0,0,0.16)';
            container.style.borderRadius = '16px';
            // On mobile, full screen
            if (window.innerWidth < 450) {
                container.style.width = '100%';
                container.style.height = '100%';
                container.style.bottom = '0';
                container.style.right = '0';
                container.style.borderRadius = '0';
            }
          } else {
            // Collapse
            container.style.width = '100px';
            container.style.height = '100px';
            container.style.bottom = '20px';
            container.style.right = '20px';
            container.style.boxShadow = 'none';
            container.style.borderRadius = '0';
          }
        }
      });
    }
  };

  window.growhubSDK = api;
  window.growWidgetSDK = api;
  log('SDK loaded');
})();
