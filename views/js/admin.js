/**
 * 2026 Moonivo
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to support@moonivo.com so we can send you a copy immediately.
 *
 * @author Moonivo <support@moonivo.com>
 * @copyright 2026 Moonivo
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
/*
 * Report page: runs the scan step by step over AJAX (one module per request, so a store
 * with many modules never hits a server time limit), then renders the report. Everything
 * shown is built with DOM nodes and textContent: file names and symbols come from the
 * store's own code and are never inserted as HTML.
 */
(function () {
  'use strict';

  var root = document.getElementById('mnv9');
  if (!root) {
    return;
  }
  var T = window.MNV9_TEXTS || {};
  var ajaxUrl = root.getAttribute('data-ajax');
  var offerUrl = root.getAttribute('data-offer');
  var careUrl = root.getAttribute('data-care');
  var pdfUrl = root.getAttribute('data-pdf');
  var runBtn = document.getElementById('mnv9-run');
  var progress = document.getElementById('mnv9-progress');
  var progressFill = document.getElementById('mnv9-progress-fill');
  var progressText = document.getElementById('mnv9-progress-text');
  var reportBox = document.getElementById('mnv9-report');

  function t(key, vars) {
    var s = T[key] || key;
    if (vars) {
      Object.keys(vars).forEach(function (k) {
        s = s.split('{' + k + '}').join(String(vars[k]));
      });
    }
    return s;
  }

  /** h('div.class', {attr}, children...) — children are nodes or plain text. */
  function h(tag, attrs) {
    var parts = tag.split('.');
    var el = document.createElement(parts[0]);
    if (parts.length > 1) {
      el.className = parts.slice(1).join(' ');
    }
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        if (k === 'text') {
          el.textContent = attrs[k];
        } else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
          el.setAttribute(k, attrs[k]);
        }
      });
    }
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c === null || c === undefined || c === false) {
        continue;
      }
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return el;
  }

  function post(params) {
    var body = new FormData();
    Object.keys(params).forEach(function (k) {
      body.append(k, params[k]);
    });
    return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) {
        throw new Error('HTTP ' + r.status);
      }
      return r.json();
    });
  }

  function setProgress(done, total, text) {
    progressFill.style.width = (total ? Math.round((done / total) * 100) : 0) + '%';
    progressText.textContent = text;
  }

  // ---- Scan -------------------------------------------------------------

  function run() {
    var report = { startedAt: Date.now(), ps: root.getAttribute('data-ps'), shop: root.getAttribute('data-shop') };
    runBtn.disabled = true;
    runBtn.textContent = t('running');
    progress.hidden = false;
    setProgress(0, 1, t('step_env'));

    post({ step: 'env' })
      .then(function (r) {
        report.env = r.env;
        setProgress(0, 1, t('step_jobs'));
        return post({ step: 'jobs' });
      })
      .then(function (r) {
        report.modules = r.modules;
        report.themes = r.themes;
        report.overrides = { files: r.overrides, result: null };
        var jobs = [];
        if (r.overrides.length) {
          jobs.push({ type: 'overrides', name: '', label: t('sec_overrides'), target: report.overrides });
        }
        r.themes.forEach(function (th) {
          if (!th.native) {
            jobs.push({ type: 'theme', name: th.name, label: th.name, target: th });
          }
        });
        r.modules.forEach(function (m) {
          if (m.kind === 'thirdparty') {
            jobs.push({ type: 'module', name: m.name, label: m.displayName, target: m });
          }
        });
        return runJobs(jobs, report);
      })
      .then(function () {
        report.finishedAt = Date.now();
        progress.hidden = true;
        render(report);
        post({ step: 'save', report: JSON.stringify(report) }).catch(function () {});
      })
      .catch(function (e) {
        setProgress(0, 1, t('failed', { error: e.message }));
      })
      .then(function () {
        runBtn.disabled = false;
        runBtn.textContent = t('rerun');
      });
  }

  function runJobs(jobs, report) {
    var i = 0;
    report.files = 0;
    function next() {
      if (i >= jobs.length) {
        return Promise.resolve();
      }
      var job = jobs[i];
      setProgress(i, jobs.length, t('step_scan', { name: job.label }) + ' (' + (i + 1) + '/' + jobs.length + ')');
      return post({ step: 'scan', type: job.type, name: job.name }).then(function (r) {
        job.target.result = r.result || { findings: [], files: 0 };
        report.files += job.target.result.files || 0;
        i++;
        return next();
      });
    }
    return next();
  }

  // ---- Summary ----------------------------------------------------------

  function counts(findings) {
    var c = { error: 0, warning: 0 };
    (findings || []).forEach(function (f) {
      c[f.severity] = (c[f.severity] || 0) + 1;
    });
    return c;
  }

  function moduleStatus(m) {
    if (m.kind === 'native' || m.kind === 'prestashop' || m.kind === 'missing') {
      return m.kind;
    }
    var c = counts(m.result && m.result.findings);
    return c.error ? 'error' : c.warning ? 'warning' : 'ok';
  }

  function summarize(report) {
    var errors = 0;
    var warnings = 0;
    var points = 0;
    var ps = report.ps || '';

    if (ps.indexOf('1.7') === 0) {
      points += 2;
    } else if (ps.indexOf('8.0') === 0 || ps.indexOf('8.1') === 0) {
      points += 1;
    }
    (report.env.checks || []).forEach(function (c) {
      if (c[1] === 'error') {
        errors++;
        points += c[0] === 'asm' ? 2 : 1;
      } else if (c[1] === 'warning') {
        warnings++;
      }
    });
    report.modules.forEach(function (m) {
      var c = counts(m.result && m.result.findings);
      errors += c.error;
      warnings += c.warning;
      if (c.error) {
        points += 1;
      }
    });
    var ov = report.overrides;
    if (ov.files.length) {
      points += 1 + Math.floor(ov.files.length / 5);
      var oc = counts(ov.result && ov.result.findings);
      errors += oc.error;
      warnings += oc.warning;
      points += oc.error ? 1 : 0;
    }
    report.themes.forEach(function (th) {
      if (th.native) {
        return;
      }
      points += th.parent ? 1 : 2;
      var tc = counts(th.result && th.result.findings);
      errors += tc.error;
      warnings += tc.warning;
      points += tc.error ? 1 : 0;
    });

    var level = points <= 2 ? 'simple' : points <= 6 ? 'medium' : 'complex';
    return { errors: errors, warnings: warnings, level: level };
  }

  // ---- Render -----------------------------------------------------------

  function render(report) {
    reportBox.textContent = '';
    var s = summarize(report);
    var ready = s.errors === 0;
    // A store already on PrestaShop 9 gets a health check, not an upgrade forecast.
    var on9 = parseInt(report.ps, 10) >= 9;
    var title = on9 ? t('verdict_on9', { version: report.ps }) : ready ? t('verdict_ready') : t('verdict_work');
    var text = on9 ? (ready ? t('verdict_on9_ok_text') : t('verdict_on9_work_text')) : ready ? t('verdict_ready_text') : t('verdict_work_text');

    var verdict = h('section.mnv9-verdict' + (ready ? '.is-ready' : '.is-work') + (on9 ? '.is-single' : ''), null,
      h('div.mnv9-verdict-main', null,
        h('h3', { text: title }),
        h('p', { text: text }),
        h('div.mnv9-counts', null,
          h('span.mnv9-count.is-error', null, h('b', { text: String(s.errors) }), ' ' + t('count_errors')),
          h('span.mnv9-count.is-warning', null, h('b', { text: String(s.warnings) }), ' ' + t('count_warnings'))
        ),
        // On PrestaShop 9 there is no effort card, so the short offer sits under the verdict:
        // the fix for what broke, or the health check for the items worth checking.
        on9 && !ready ? offerLink(on9, 'verdict') : on9 && s.warnings ? careLink('verdict') : null
      ),
      on9 ? null : h('div.mnv9-level.is-' + s.level, null,
        h('p.mnv9-level-label', { text: t('level') }),
        h('p.mnv9-level-value', { text: t('level_' + s.level) }),
        h('p.mnv9-level-text', { text: t('level_' + s.level + '_text') }),
        h('p.mnv9-level-note', { text: t('level_note') }),
        // The offer where the reader is deciding how big the job is; the full block stays at the end.
        ready ? null : offerLink(on9, 'effort')
      )
    );
    reportBox.appendChild(verdict);
    T.st_native = on9 ? t('st_native_9') : t('st_native_upgrade');
    T.theme_native = on9 ? t('theme_native_9') : t('theme_native_upgrade');

    reportBox.appendChild(renderServer(report.env));
    reportBox.appendChild(renderModules(report.modules));
    reportBox.appendChild(renderThemes(report.themes));
    reportBox.appendChild(renderOverrides(report.overrides));
    // A store already fine on PrestaShop 9 has no upgrade to buy; it gets the health check or maintenance instead.
    reportBox.appendChild(on9 && ready ? renderCare(s.warnings > 0) : renderOffer(on9));

    var seconds = Math.max(1, Math.round(((report.finishedAt || 0) - (report.startedAt || 0)) / 1000));
    var foot = h('div.mnv9-foot', null,
      h('p', { text: t('last_run', { date: new Date(report.finishedAt || Date.now()).toLocaleString() }) + ' · ' + t('done', { seconds: seconds, files: report.files || 0 }) }),
      h('div', null,
        button(t('download'), function () {
          window.location.href = pdfUrl;
        }),
        button(t('print'), function () {
          window.print();
        })
      )
    );
    reportBox.appendChild(foot);
  }

  function button(label, onClick, cls) {
    var b = h('button.mnv9-btn' + (cls || ''), { type: 'button', text: label });
    b.addEventListener('click', onClick);
    return b;
  }

  function section(title, badge) {
    var sec = h('section.mnv9-section', null, h('h3.mnv9-section-title', null, title, badge || null));
    return sec;
  }

  function badgeFor(findings) {
    var c = counts(findings);
    if (c.error) {
      return h('span.mnv9-badge.is-error', { text: String(c.error) + ' ✕' });
    }
    if (c.warning) {
      return h('span.mnv9-badge.is-warning', { text: String(c.warning) + ' !' });
    }
    return h('span.mnv9-badge.is-ok', { text: '✓' });
  }

  function renderServer(env) {
    var sec = section(t('sec_server'));
    var list = h('ul.mnv9-checks');
    env.checks.forEach(function (c) {
      var id = c[0];
      var status = c[1];
      var current = c[2];
      var needed = c[3];
      var detail = '';
      if (id === 'extensions') {
        detail = status === 'ok' ? '' : t('env_extensions_missing', { value: current });
      } else if (current !== '') {
        detail = t('current', { value: current }) + (status !== 'ok' && needed ? ' · ' + t('required', { value: needed }) : '');
      }
      var help = status !== 'ok' && T['env_' + id + '_help'] ? t('env_' + id + '_help') : '';
      list.appendChild(h('li.mnv9-check.is-' + status, null,
        h('span.mnv9-dot'),
        h('div', null,
          h('p.mnv9-check-name', null, t('env_' + id), detail ? h('span.mnv9-muted', { text: ' ' + detail }) : null),
          help ? h('p.mnv9-help', { text: help }) : null
        )
      ));
    });
    sec.appendChild(list);
    return sec;
  }

  function renderModules(modules) {
    var order = { error: 0, warning: 1, missing: 2, prestashop: 3, ok: 4, native: 5 };
    var rows = modules.slice().sort(function (a, b) {
      var d = order[moduleStatus(a)] - order[moduleStatus(b)];
      return d !== 0 ? d : a.displayName.localeCompare(b.displayName);
    });
    var sec = section(t('sec_modules'), h('span.mnv9-muted', { text: ' ' + modules.length }));
    var list = h('div.mnv9-modules');
    // The modules PrestaShop ships are replaced by the upgrade itself: one folded row.
    var natives = rows.filter(function (m) {
      return moduleStatus(m) === 'native';
    });
    rows = rows.filter(function (m) {
      return moduleStatus(m) !== 'native';
    });
    rows.forEach(function (m) {
      list.appendChild(moduleRow(m));
    });
    if (natives.length) {
      var inner = h('div.mnv9-native-list');
      natives.forEach(function (m) {
        inner.appendChild(moduleRow(m));
      });
      list.appendChild(h('details.mnv9-module.is-native', null,
        h('summary.mnv9-module-head', null,
          h('span.mnv9-dot'),
          h('div.mnv9-module-name', null, h('b', { text: t('native_group', { n: natives.length }) })),
          h('span.mnv9-module-status', { text: t('st_native') })
        ),
        inner
      ));
    }
    sec.appendChild(list);
    return sec;
  }

  function moduleRow(m) {
      var status = moduleStatus(m);
      var findings = (m.result && m.result.findings) || [];
      var row = h('div.mnv9-module.is-' + status, null,
        h('div.mnv9-module-head', null,
          h('span.mnv9-dot'),
          h('div.mnv9-module-name', null,
            h('b', { text: m.displayName }),
            h('span.mnv9-muted', { text: ' ' + m.name + ' ' + m.version + (m.author ? ' · ' + m.author : '') + (m.active ? '' : ' · ' + t('disabled')) })
          ),
          h('span.mnv9-module-status', { text: t('st_' + status) })
        )
      );
      if (findings.length) {
        row.appendChild(findingList(findings));
      }
      return row;
  }

  function renderThemes(themes) {
    var sec = section(t('sec_theme'));
    themes.forEach(function (th) {
      var lines = [];
      if (th.native) {
        lines.push(t('theme_native'));
      } else {
        lines.push(th.parent ? t('theme_child', { parent: th.parent }) : t('theme_custom'));
      }
      if (th.from || th.to) {
        lines.push(t('theme_compat', { from: th.from || '?', to: th.to || '?' }));
      }
      var findings = (th.result && th.result.findings) || [];
      var box = h('div.mnv9-theme', null,
        h('p', null, h('b', { text: th.name }), th.version ? h('span.mnv9-muted', { text: ' ' + th.version }) : null, ' ', th.native ? null : badgeFor(findings)),
        h('p.mnv9-muted', { text: lines.join(' ') })
      );
      if (findings.length) {
        box.appendChild(findingList(findings));
      }
      sec.appendChild(box);
    });
    return sec;
  }

  function renderOverrides(ov) {
    var findings = (ov.result && ov.result.findings) || [];
    var sec = section(t('sec_overrides'), ov.files.length ? h('span.mnv9-muted', { text: ' ' + ov.files.length }) : null);
    if (!ov.files.length) {
      sec.appendChild(h('p.mnv9-muted', { text: t('no_overrides') }));
      return sec;
    }
    sec.appendChild(h('p.mnv9-help', { text: t('override_note') }));
    sec.appendChild(h('ul.mnv9-files', null));
    var ul = sec.lastChild;
    ov.files.forEach(function (f) {
      ul.appendChild(h('li', { text: 'override/' + f }));
    });
    if (findings.length) {
      sec.appendChild(findingList(findings));
    }
    return sec;
  }

  function findingList(findings) {
    var sorted = findings.slice().sort(function (a, b) {
      return (a.severity === 'error' ? 0 : 1) - (b.severity === 'error' ? 0 : 1);
    });
    var ul = h('ul.mnv9-findings');
    sorted.forEach(function (f) {
      var text = t('f_' + f.code, { subject: f.subject, extra: f.extra || '' });
      var where = f.file + (f.lines && f.lines.length ? ' · ' + t('line') + ' ' + f.lines.join(', ') + (f.count > f.lines.length ? '…' : '') : '');
      ul.appendChild(h('li.is-' + f.severity, null,
        h('p', { text: text }),
        h('p.mnv9-where', null,
          h('code', { text: where }),
          f.library ? h('span.mnv9-muted', { text: ' · ' + t('library_note') }) : null
        ),
        f.severity === 'warning' && !f.library && ['compliancy', 'tplvar', 'removed_service', 'admin_silent'].indexOf(f.code) === -1
          ? h('p.mnv9-muted', { text: t('f_guarded') })
          : null
      ));
    });
    return ul;
  }

  function offerHref(on9, place) {
    return offerUrl + '?utm_source=ps9check&utm_medium=module&utm_campaign=' + (on9 ? 'report-ps9' : 'report') + '&utm_content=' + place;
  }

  function offerLink(on9, place) {
    return h('a.mnv9-offer-link', { href: offerHref(on9, place), target: '_blank', rel: 'noopener', text: t(on9 ? 'offer9_inline' : 'offer_inline') + ' →' });
  }

  function careHref(place) {
    return careUrl + '?utm_source=ps9check&utm_medium=module&utm_campaign=report-care&utm_content=' + place + '#packages';
  }

  function careLink(place) {
    return h('a.mnv9-offer-link', { href: careHref(place), target: '_blank', rel: 'noopener', text: t('care_warn_inline') + ' →' });
  }

  function renderCare(warn) {
    var k = warn ? 'care_warn_' : 'care_ok_';
    return h('section.mnv9-offer', null,
      h('div', null,
        h('h3', { text: t(k + 'title') }),
        h('p', { text: t(k + 'text') })
      ),
      h('div.mnv9-offer-cta', null,
        h('a.mnv9-btn.mnv9-btn--primary', { href: careHref('bottom'), target: '_blank', rel: 'noopener', text: t(k + 'button') }),
        warn ? h('p.mnv9-muted', { text: t('care_warn_from') }) : null
      )
    );
  }

  function renderOffer(on9) {
    var url = offerHref(on9, 'bottom');
    return h('section.mnv9-offer', null,
      h('div', null,
        h('h3', { text: t(on9 ? 'offer9_title' : 'offer_title') }),
        h('p', { text: t(on9 ? 'offer9_text' : 'offer_text') })
      ),
      h('div.mnv9-offer-cta', null,
        h('a.mnv9-btn.mnv9-btn--primary', { href: url, target: '_blank', rel: 'noopener', text: t(on9 ? 'offer9_button' : 'offer_button') }),
        on9 ? null : h('p.mnv9-muted', { text: t('offer_from') })
      )
    );
  }

  // ---- Start ------------------------------------------------------------

  document.getElementById('mnv9-privacy').textContent = t('privacy');
  runBtn.textContent = window.MNV9_LAST ? t('rerun') : t('run');
  runBtn.addEventListener('click', run);
  if (window.MNV9_LAST && window.MNV9_LAST.env) {
    render(window.MNV9_LAST);
  }
})();
