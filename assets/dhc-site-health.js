/**
 * Dsquared Hub Connector — Core Web Vitals Reporter
 *
 * Lightweight script that collects real-user CWV metrics (LCP, FID, CLS, TTFB, INP, FCP)
 * using the web-vitals library pattern and reports them to the Hub.
 * ~2KB minified. No dependencies. Non-blocking.
 */
(function() {
  'use strict';

  var config = window.dhcHealthConfig || {};
  if (!config.endpoint) return;

  // Sample rate check (default 100%)
  var sampleRate = config.sampleRate || 100;
  if (Math.random() * 100 > sampleRate) return;

  var metrics = {};
  var reported = false;

  /**
   * Observe a Performance Entry type and call handler
   */
  function observe(type, callback) {
    try {
      var po = new PerformanceObserver(function(list) {
        list.getEntries().forEach(callback);
      });
      po.observe({ type: type, buffered: true });
    } catch (e) {
      // Observer not supported for this type
    }
  }

  // LCP — Largest Contentful Paint
  observe('largest-contentful-paint', function(entry) {
    metrics.lcp = Math.round(entry.startTime);
  });

  // FID — First Input Delay
  observe('first-input', function(entry) {
    metrics.fid = Math.round(entry.processingStart - entry.startTime);
  });

  // CLS — Cumulative Layout Shift
  var clsValue = 0;
  var clsEntries = [];
  var sessionValue = 0;
  var sessionEntries = [];

  observe('layout-shift', function(entry) {
    if (!entry.hadRecentInput) {
      var firstEntry = sessionEntries[0];
      var lastEntry = sessionEntries[sessionEntries.length - 1];

      if (sessionValue &&
          entry.startTime - lastEntry.startTime < 1000 &&
          entry.startTime - firstEntry.startTime < 5000) {
        sessionValue += entry.value;
        sessionEntries.push(entry);
      } else {
        sessionValue = entry.value;
        sessionEntries = [entry];
      }

      if (sessionValue > clsValue) {
        clsValue = sessionValue;
        clsEntries = sessionEntries.slice();
        metrics.cls = parseFloat(clsValue.toFixed(4));
      }
    }
  });

  // FCP — First Contentful Paint
  observe('paint', function(entry) {
    if (entry.name === 'first-contentful-paint') {
      metrics.fcp = Math.round(entry.startTime);
    }
  });

  // INP — Interaction to Next Paint
  var inpEntries = [];
  observe('event', function(entry) {
    if (entry.interactionId) {
      inpEntries.push(entry);
      // INP is the p98 of all interactions
      var durations = inpEntries.map(function(e) { return e.duration; });
      durations.sort(function(a, b) { return a - b; });
      var idx = Math.min(durations.length - 1, Math.ceil(durations.length * 0.98) - 1);
      metrics.inp = durations[idx];
    }
  });

  // TTFB — Time to First Byte
  try {
    var navEntry = performance.getEntriesByType('navigation')[0];
    if (navEntry) {
      metrics.ttfb = Math.round(navEntry.responseStart);
    }
  } catch (e) {}

  /**
   * Send metrics to the endpoint
   */
  function report() {
    if (reported) return;
    if (!metrics.lcp && !metrics.fcp) return; // Wait for at least one paint metric

    reported = true;

    var payload = {
      metrics: metrics,
      url: window.location.href,
      user_agent: navigator.userAgent
    };

    // Use sendBeacon for reliability during page unload
    if (navigator.sendBeacon) {
      var blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
      navigator.sendBeacon(config.endpoint, blob);
    } else {
      // Fallback to fetch
      fetch(config.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        keepalive: true
      }).catch(function() {});
    }

    // Hub forwarding is handled server-side by the /health endpoint.
    // The API key is never passed to the browser.
  }

  // Report on page visibility change (user navigating away)
  document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') {
      report();
    }
  });

  // Fallback: report after 30 seconds if still on page
  setTimeout(report, 30000);

  // Report on page unload (backup)
  window.addEventListener('pagehide', report);
})();
