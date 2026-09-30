// /assets/js/rtf_editor.js
//
// Shared editor modal. Three modes:
//
//   mode: 'editor', format: 'rtf'    — toolbar + contenteditable (WYSIWYG)
//   mode: 'editor', format: 'html'   — toolbar + textarea + live preview
//                                      (toolbar inserts real HTML tags)
//   mode: 'email'                    — To + Subject + toolbar + contenteditable,
//                                      POSTs to /assets/php/send_mail.php
//
// HTML mode is Chinese-IME-friendly on iOS because it uses a plain
// <textarea> where the WebKit composition model is stable. The toolbar
// inserts real HTML tags (<b>, <i>, <ul>, <div style="text-align:…">),
// so the underlying content language is the same as RTF mode.
//
// Content representation in HTML mode:
//   - The textarea holds editable HTML source.
//   - Block-level content (lines, paragraphs) is represented as <div>.
//   - Inline formatting tags (b, i, u) are typed or inserted by the toolbar.
//   - Empty lines are <div><br></div>.
//   - Switching to RTF mode is a lossless view change: the same HTML
//     is dropped into a contenteditable, which natively handles <div>
//     as line breaks.
//
// `format: 'plain'` is accepted as an alias for `'html'` for backwards
// compatibility with existing callers.
//
// Options:
//   mode              'editor' | 'email'   (default 'editor')
//   format            'rtf' | 'html' | 'plain'   (default 'rtf')
//   title             Modal title
//   initialHtml       Pre-filled content (HTML, for any editor mode)
//   recipients        Email mode: comma-separated recipients
//   subject           Email mode: subject line
//   onApply(html)     Editor mode callback, receives updated HTML
//   onCancel()        Called when Cancel is clicked
//   onSent()          Called after email sends successfully
//   align             'center' | 'left' | 'right'  (default 'center')
//   dimensions        '750px, 750px' | ...
//   editorMinHeight   RTF editable area min height in px (default 300)
//   editorMaxHeight   RTF editable area max height in px (default 500)
//
// Requires: /assets/js/dialog.js (showModalDialog), /assets/css/rtf_modal.css

(function() {
    'use strict';

    // =====================================================================
    // Escape helper
    // =====================================================================
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // =====================================================================
    // Editable-text ↔ block-HTML conversion (used only in HTML mode)
    // =====================================================================

    // Convert loaded HTML into editable text for the textarea.
    // Block elements (div, p, br) become newlines.
    // Inline tags (b, i, u, span, a) are preserved as literal text.
    function htmlToEditableText(html) {
        if (!html) return '';
        var div = document.createElement('div');
        div.innerHTML = html;
        var out = '';

        function walk(node) {
            if (node.nodeType === 3) { out += node.textContent; return; }
            if (node.nodeType !== 1) return;
            var tag = node.tagName;

            if (tag === 'BR') { out += '\n'; return; }

            if (tag === 'DIV' || tag === 'P') {
                for (var d = 0; d < node.childNodes.length; d++) walk(node.childNodes[d]);
                out += '\n';
                return;
            }

            if (tag === 'UL' || tag === 'OL') {
                out += '<' + tag.toLowerCase() + '>\n';
                for (var k = 0; k < node.children.length; k++) {
                    var li = node.children[k];
                    if (li.tagName !== 'LI') continue;
                    out += '  <li>';
                    for (var c = 0; c < li.childNodes.length; c++) walk(li.childNodes[c]);
                    out += '</li>\n';
                }
                out += '</' + tag.toLowerCase() + '>';
                return;
            }

            if (['B', 'I', 'U', 'STRONG', 'EM', 'SPAN', 'A'].indexOf(tag) !== -1) {
                out += '<' + tag.toLowerCase() + '>';
                for (var ci = 0; ci < node.childNodes.length; ci++) walk(node.childNodes[ci]);
                out += '</' + tag.toLowerCase() + '>';
                return;
            }

            for (var f = 0; f < node.childNodes.length; f++) walk(node.childNodes[f]);
        }

        walk(div);
        return out.replace(/^\n+/, '').replace(/\n+$/, '');
    }

    // Convert editable text back into block HTML for storage.
    // Each line that isn't already a block-level element becomes
    // <div>line</div>. Empty lines become <div><br></div>.
    function textToBlockHtml(text) {
        var BLOCK_START = /^<(\/?)(div|p|ul|ol|li|br|h[1-6])\b/i;
        return String(text || '').split('\n').map(function(line) {
            var trimmed = line.trim();
            if (BLOCK_START.test(trimmed)) return line;
            return '<div>' + (line === '' ? '<br>' : line) + '</div>';
        }).join('');
    }

    // =====================================================================
    // Client-side HTML sanitizer for the live preview
    // =====================================================================
    // Same whitelist as the server-side sanitizer. Strips <script>,
    // <iframe>, on* attributes, javascript: URLs, and style attributes
    // except text-align on <div>. Used only for the live preview;
    // the server re-sanitizes on save.
    function sanitizeHtmlForPreview(html) {
        if (!html) return '';
        var doc = document.createElement('div');
        doc.innerHTML = html;

        var allowedTags = {
            'DIV': true, 'P': true, 'BR': true, 'OL': true, 'UL': true,
            'LI': true, 'B': true, 'STRONG': true, 'I': true, 'EM': true,
            'U': true, 'SPAN': true, 'A': true
        };

        function walk(node) {
            if (node.nodeType === 3) return;
            if (node.nodeType !== 1) {
                node.remove();
                return;
            }

            var tag = node.tagName;

            var children = Array.prototype.slice.call(node.childNodes);
            for (var i = 0; i < children.length; i++) walk(children[i]);

            if (!allowedTags[tag]) {
                var parent = node.parentNode;
                while (node.firstChild) parent.insertBefore(node.firstChild, node);
                parent.removeChild(node);
                return;
            }

            var attrs = Array.prototype.slice.call(node.attributes);
            for (var a = 0; a < attrs.length; a++) {
                var name = attrs[a].name.toLowerCase();
                var value = attrs[a].value;

                if (tag === 'A' && name === 'href') {
                    if (!/^https?:\/\//i.test(value)) node.removeAttribute('href');
                    continue;
                }
                if (tag === 'DIV' && name === 'style') {
                    var m = value.match(/text-align\s*:\s*(left|right|center|justify)/i);
                    if (m) node.setAttribute('style', 'text-align:' + m[1].toLowerCase() + ';');
                    else node.removeAttribute('style');
                    continue;
                }
                node.removeAttribute(attrs[a].name);
            }
        }

        var topChildren = Array.prototype.slice.call(doc.childNodes);
        for (var i = 0; i < topChildren.length; i++) walk(topChildren[i]);
        return doc.innerHTML;
    }

    // =====================================================================
    // Plain-text extraction (utility, not on the switch path)
    // =====================================================================
    function htmlToPlainText(html) {
        if (!html) return '';
        var div = document.createElement('div');
        div.innerHTML = html;
        var out = '';

        function walk(node) {
            if (node.nodeType === 3) { out += node.textContent; return; }
            if (node.nodeType !== 1) return;
            var tag = node.tagName;

            if (tag === 'BR') { out += '\n'; return; }

            if (tag === 'UL' || tag === 'OL') {
                if (out && out.charAt(out.length - 1) !== '\n') out += '\n';
                for (var k = 0; k < node.children.length; k++) {
                    var li = node.children[k];
                    if (li.tagName !== 'LI') continue;
                    if (k > 0) out += '\n';
                    for (var c = 0; c < li.childNodes.length; c++) walk(li.childNodes[c]);
                }
                if (out.charAt(out.length - 1) !== '\n') out += '\n';
                return;
            }

            if (tag === 'DIV' || tag === 'P' || tag === 'H1' || tag === 'H2' || tag === 'H3') {
                if (out && out.charAt(out.length - 1) !== '\n') out += '\n';
                for (var d = 0; d < node.childNodes.length; d++) walk(node.childNodes[d]);
                if (out.charAt(out.length - 1) !== '\n') out += '\n';
                return;
            }

            for (var f = 0; f < node.childNodes.length; f++) walk(node.childNodes[f]);
        }

        walk(div);

        var cleaned = out.replace(/\u00a0/g, ' ');
        var lines = cleaned.split('\n').map(function(line) {
            return line.replace(/[ \t]+/g, ' ').trim();
        });
        return lines.join('\n').replace(/\n{3,}/g, '\n\n').replace(/^\n+/, '').replace(/\n+$/, '');
    }

    // =====================================================================
    // HTML cheat-sheet modal (independent overlay)
    // =====================================================================
    function openSyntaxHelp() {
        if (document.getElementById('rtfHelpOverlay')) return;

        var contentHtml =
            '<div style="text-align:left; font-size:13px; line-height:1.7; max-height:60vh; overflow-y:auto;">' +

            '<div style="font-weight:700; color:#0078d4; margin-top:4px;">INLINE FORMATTING</div>' +
            '<div style="font-family:\'SF Mono\',Consolas,monospace; padding-left:10px; margin-bottom:12px;">' +
                '&lt;b&gt;bold&lt;/b&gt;<br>' +
                '&lt;i&gt;italic&lt;/i&gt;<br>' +
                '&lt;u&gt;underline&lt;/u&gt;' +
            '</div>' +

            '<div style="font-weight:700; color:#0078d4;">LISTS</div>' +
            '<div style="font-family:\'SF Mono\',Consolas,monospace; padding-left:10px; margin-bottom:12px;">' +
                '&lt;ul&gt;&lt;li&gt;bullet&lt;/li&gt;&lt;/ul&gt;<br>' +
                '&lt;ol&gt;&lt;li&gt;numbered&lt;/li&gt;&lt;/ol&gt;' +
            '</div>' +

            '<div style="font-weight:700; color:#0078d4;">ALIGNMENT</div>' +
            '<div style="font-family:\'SF Mono\',Consolas,monospace; padding-left:10px; margin-bottom:12px;">' +
                '&lt;div style="text-align: center;"&gt;centered&lt;/div&gt;<br>' +
                '&lt;div style="text-align: right;"&gt;right-aligned&lt;/div&gt;' +
            '</div>' +

            '<div style="font-weight:700; color:#0078d4;">TIP</div>' +
            '<div style="padding-left:10px; color:#444;">' +
                'Select text and click a toolbar button — the tags are inserted around your selection automatically. Press Enter for a new line.' +
            '</div>' +

            '</div>';

        var overlay = document.createElement('div');
        overlay.id = 'rtfHelpOverlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;'
            + 'background:rgba(0,0,0,0.35);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);'
            + 'z-index:10050;display:flex;justify-content:center;align-items:center;';

        var panel = document.createElement('div');
        panel.style.cssText = 'background:rgba(255,255,255,0.98);padding:20px 24px;border-radius:12px;'
            + 'box-shadow:0 10px 30px rgba(0,0,0,0.2);max-width:560px;max-height:85vh;overflow:hidden;'
            + 'display:flex;flex-direction:column;gap:12px;';
        panel.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;">' +
                '<h3 style="margin:0;font-size:16px;color:#111;">HTML Cheat Sheet</h3>' +
                '<button type="button" id="rtfHelpClose" style="background:none;border:none;font-size:20px;'
                + 'cursor:pointer;color:#888;line-height:1;padding:0;">&times;</button>' +
            '</div>' +
            '<div style="overflow-y:auto;">' + contentHtml + '</div>';

        overlay.appendChild(panel);
        document.body.appendChild(overlay);

        function closeHelp() {
            var el = document.getElementById('rtfHelpOverlay');
            if (el) el.remove();
        }

        document.getElementById('rtfHelpClose').addEventListener('click', closeHelp);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) closeHelp(); });
        document.addEventListener('keydown', function onEsc(e) {
            if (e.key === 'Escape') { closeHelp(); document.removeEventListener('keydown', onEsc); }
        });
    }

    // =====================================================================
    // Toolbar definition
    // =====================================================================
    var TOOLBAR = [
        { cmd: 'bold',                label: '<b>B</b>', title: 'Bold',          group: 0 },
        { cmd: 'italic',              label: '<i>I</i>', title: 'Italic',        group: 0 },
        { cmd: 'underline',           label: '<u>U</u>', title: 'Underline',     group: 0 },
        { cmd: 'insertUnorderedList', label: '•',        title: 'Bullet List',   group: 1 },
        { cmd: 'insertOrderedList',   label: '1.',       title: 'Numbered List', group: 1 },
        { cmd: 'justifyLeft',         label: '⬅',       title: 'Align Left',    group: 2 },
        { cmd: 'justifyCenter',       label: '⬌',       title: 'Align Center',  group: 2 },
        { cmd: 'justifyRight',        label: '➡',       title: 'Align Right',   group: 2 }
    ];

    function buildToolbarHtml(prefix) {
        var html = '<div class="rtf-toolbar">';
        var lastGroup = -1;
        for (var i = 0; i < TOOLBAR.length; i++) {
            var t = TOOLBAR[i];
            if (lastGroup !== -1 && t.group !== lastGroup) {
                html += '<span class="rtf-separator"></span>';
            }
            html += '<button type="button" class="rtf-btn" data-cmd="' + t.cmd + '" ' +
                    'id="' + prefix + '_btn_' + i + '" ' +
                    'title="' + escapeHtml(t.title) + '">' + t.label + '</button>';
            lastGroup = t.group;
        }
        html += '</div>';
        return html;
    }

    // ---- RTF mode: execCommand on the contenteditable ----
    function wireToolbarRtf(prefix, bodyId, btnCount) {
        var body = document.getElementById(bodyId);
        if (!body) return;

        for (var i = 0; i < btnCount; i++) {
            var btn = document.getElementById(prefix + '_btn_' + i);
            if (!btn) continue;
            (function(b) {
                b.addEventListener('mousedown', function(e) { e.preventDefault(); });
                b.addEventListener('click', function(e) {
                    e.preventDefault();
                    var cmd = b.getAttribute('data-cmd');
                    try { document.execCommand(cmd, false, null); } catch (err) {}
                    body.focus();
                    refreshToolbarStateRtf(prefix, bodyId, btnCount);
                });
            })(btn);
        }

        body.addEventListener('keyup', function() { refreshToolbarStateRtf(prefix, bodyId, btnCount); });
        body.addEventListener('mouseup', function() { refreshToolbarStateRtf(prefix, bodyId, btnCount); });
        body.addEventListener('input', function() { refreshToolbarStateRtf(prefix, bodyId, btnCount); });
        document.addEventListener('selectionchange', function() {
            if (document.activeElement === body || body.contains(document.activeElement)) {
                refreshToolbarStateRtf(prefix, bodyId, btnCount);
            }
        });

        setTimeout(function() { refreshToolbarStateRtf(prefix, bodyId, btnCount); }, 50);
    }

    function refreshToolbarStateRtf(prefix, bodyId, btnCount) {
        var body = document.getElementById(bodyId);
        if (!body) return;
        for (var i = 0; i < btnCount; i++) {
            var btn = document.getElementById(prefix + '_btn_' + i);
            if (!btn) continue;
            var cmd = btn.getAttribute('data-cmd');
            var isActive = false;
            try { isActive = document.queryCommandState(cmd); } catch (err) { isActive = false; }
            if (isActive) btn.classList.add('rtf-active');
            else          btn.classList.remove('rtf-active');
        }
    }

    // ---- HTML mode: buttons insert real HTML tags into the textarea ----
    function insertInlineTag(ta, openTag, closeTag) {
        var start = ta.selectionStart;
        var end   = ta.selectionEnd;
        var text  = ta.value;
        var sel   = text.substring(start, end);
        var before = text.substring(0, start);
        var after  = text.substring(end);

        // If the selection is already wrapped, unwrap it (toggle behavior).
        if (before.slice(-openTag.length) === openTag && after.slice(0, closeTag.length) === closeTag) {
            ta.value = before.slice(0, -openTag.length) + sel + after.slice(closeTag.length);
            ta.setSelectionRange(start - openTag.length, end - openTag.length);
            ta.focus();
            return;
        }

        if (sel.length > 0) {
            var replacement = openTag + sel + closeTag;
            ta.value = text.substring(0, start) + replacement + text.substring(end);
            ta.setSelectionRange(start + openTag.length, start + openTag.length + sel.length);
        } else {
            var replacement = openTag + closeTag;
            ta.value = text.substring(0, start) + replacement + text.substring(end);
            ta.setSelectionRange(start + openTag.length, start + openTag.length);
        }
        ta.focus();
    }

    function wrapLinesInList(ta, listTag) {
        var start = ta.selectionStart;
        var end   = ta.selectionEnd;
        var text  = ta.value;

        var lineStart = text.lastIndexOf('\n', start - 1) + 1;
        var lineEnd   = text.indexOf('\n', end);
        if (lineEnd === -1) lineEnd = text.length;

        var blockText = text.substring(lineStart, lineEnd);
        var lines = blockText.split('\n').map(function(l) { return l.trim(); }).filter(function(l) { return l.length > 0; });
        if (lines.length === 0) { ta.focus(); return; }

        var wrapped = '<' + listTag + '>\n' + lines.map(function(l) {
            return '  <li>' + l + '</li>';
        }).join('\n') + '\n</' + listTag + '>';

        ta.value = text.substring(0, lineStart) + wrapped + text.substring(lineEnd);
        ta.setSelectionRange(lineStart, lineStart + wrapped.length);
        ta.focus();
    }

    function wrapLinesInAlignment(ta, alignKind) {
        var start = ta.selectionStart;
        var end   = ta.selectionEnd;
        var text  = ta.value;

        var lineStart = text.lastIndexOf('\n', start - 1) + 1;
        var lineEnd   = text.indexOf('\n', end);
        if (lineEnd === -1) lineEnd = text.length;

        var blockText = text.substring(lineStart, lineEnd);
        if (!blockText.trim()) { ta.focus(); return; }

        var wrapped;
        var existing = blockText.trim().match(/^<div\s+style="text-align:[^"]*"\s*>([\s\S]*)<\/div>$/i);
        if (existing) {
            var currentAlign = blockText.trim().match(/text-align:\s*(left|right|center|justify)/i);
            if (currentAlign && currentAlign[1].toLowerCase() === alignKind) {
                // Same alignment: unwrap.
                wrapped = existing[1];
            } else {
                // Different alignment: replace the wrapper.
                wrapped = '<div style="text-align: ' + alignKind + ';">' + existing[1] + '</div>';
            }
        } else {
            wrapped = '<div style="text-align: ' + alignKind + ';">' + blockText.trim() + '</div>';
        }

        ta.value = text.substring(0, lineStart) + wrapped + text.substring(lineEnd);
        ta.setSelectionRange(lineStart, lineStart + wrapped.length);
        ta.focus();
    }

    function wireToolbarHtml(prefix, bodyId, btnCount, onEdit) {
        var ta = document.getElementById(bodyId);
        if (!ta) return;

        for (var i = 0; i < btnCount; i++) {
            var btn = document.getElementById(prefix + '_btn_' + i);
            if (!btn) continue;
            (function(b) {
                b.addEventListener('mousedown', function(e) { e.preventDefault(); });
                b.addEventListener('click', function(e) {
                    e.preventDefault();
                    var cmd = b.getAttribute('data-cmd');

                    if (cmd === 'bold')            insertInlineTag(ta, '<b>', '</b>');
                    else if (cmd === 'italic')     insertInlineTag(ta, '<i>', '</i>');
                    else if (cmd === 'underline')  insertInlineTag(ta, '<u>', '</u>');
                    else if (cmd === 'insertUnorderedList') wrapLinesInList(ta, 'ul');
                    else if (cmd === 'insertOrderedList')   wrapLinesInList(ta, 'ol');
                    else if (cmd === 'justifyLeft')   wrapLinesInAlignment(ta, 'left');
                    else if (cmd === 'justifyCenter') wrapLinesInAlignment(ta, 'center');
                    else if (cmd === 'justifyRight')  wrapLinesInAlignment(ta, 'right');

                    if (typeof onEdit === 'function') onEdit();
                });
            })(btn);
        }
    }

    // =====================================================================
    // iOS detection
    // =====================================================================
    function rtfIsIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
               (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    // =====================================================================
    // Email send
    // =====================================================================
    function sendEmail(bodyId, recipientsId, subjectId, statusId) {
        var body     = document.getElementById(bodyId);
        var recips   = document.getElementById(recipientsId);
        var subj     = document.getElementById(subjectId);
        var statusEl = document.getElementById(statusId);

        var recipients = (recips ? recips.value : '').trim();
        var subject    = (subj   ? subj.value   : '').trim();
        var messageHtml = body ? body.innerHTML : '';

        if (!recipients) {
            if (statusEl) { statusEl.style.color = '#dc3545'; statusEl.textContent = 'Recipient is required.'; }
            return false;
        }
        if (!subject) {
            if (statusEl) { statusEl.style.color = '#dc3545'; statusEl.textContent = 'Subject is required.'; }
            return false;
        }

        var formData = new FormData();
        formData.append('to', recipients);
        formData.append('subject', subject);
        formData.append('message', messageHtml);
        formData.append('csrf_token', window.__rtfEditorCsrf || '');

        if (statusEl) { statusEl.style.color = '#28a745'; statusEl.textContent = 'Sending...'; }

        fetch('/assets/php/send_mail.php', { method: 'POST', body: formData })
            .then(function(res) { return res.json(); })
            .then(function(resp) {
                if (resp.status === 'success') {
                    if (statusEl) { statusEl.style.color = '#28a745'; statusEl.textContent = '✓ Message sent.'; }
                    setTimeout(function() {
                        var modal = document.getElementById('globalPortalModal');
                        if (modal) modal.remove();
                        if (typeof window.__rtfEditorOnSent === 'function') window.__rtfEditorOnSent();
                    }, 600);
                } else {
                    if (statusEl) { statusEl.style.color = '#dc3545'; statusEl.textContent = '✕ ' + (resp.message || 'Send failed.'); }
                }
            })
            .catch(function(err) {
                console.error('Email send error:', err);
                if (statusEl) { statusEl.style.color = '#dc3545'; statusEl.textContent = '✕ Network error.'; }
            });

        return false;
    }

    // =====================================================================
    // Public entry point
    // =====================================================================

    window.openRtfEditor = function(options) {
        options = options || {};

        var mode    = options.mode === 'email' ? 'email' : 'editor';
        var isEmail = (mode === 'email');

        var formatRaw = (options.format || 'rtf').toLowerCase();
        if (formatRaw === 'plain') formatRaw = 'html';
        var isHtml = !isEmail && formatRaw === 'html';
        var isRtf  = !isEmail && !isHtml;

        var title       = options.title || (isEmail ? 'Compose Message' : 'Edit Content');
        var initialHtml = options.initialHtml || '';

        var align = options.align || 'center';

        var rtfMinHeight = options.editorMinHeight || 300;
        var rtfMaxHeight = options.editorMaxHeight || 500;

        var uid = 'rtf' + Date.now() + Math.floor(Math.random() * 1000);
        var bodyId       = uid + '_body';
        var previewId    = uid + '_preview';
        var recipientsId = uid + '_recipients';
        var subjectId    = uid + '_subject';
        var statusId     = uid + '_status';
        var prefix       = uid;

        // ---- Email header fields (email mode only) ----
        var emailFieldsHtml = '';
        if (isEmail) {
            emailFieldsHtml =
                '<div style="margin-bottom: 10px;">' +
                    '<label style="font-size: 12px; font-weight: bold; color: rgba(var(--body-txt-rgb), 0.7); display: block; margin-bottom: 4px;">To:</label>' +
                    '<input type="text" id="' + recipientsId + '" value="' + escapeHtml(options.recipients || '') + '" ' +
                    'style="width: 100%; padding: 6px 8px; box-sizing: border-box; border: 1px solid #888; border-radius: 4px; background: rgba(var(--card-bg-rgb), 1); color: rgba(var(--body-txt-rgb), 1); font-size: 13px;">' +
                '</div>' +
                '<div style="margin-bottom: 10px;">' +
                    '<label style="font-size: 12px; font-weight: bold; color: rgba(var(--body-txt-rgb), 0.7); display: block; margin-bottom: 4px;">Subject:</label>' +
                    '<input type="text" id="' + subjectId + '" value="' + escapeHtml(options.subject || '') + '" ' +
                    'style="width: 100%; padding: 6px 8px; box-sizing: border-box; border: 1px solid #888; border-radius: 4px; background: rgba(var(--card-bg-rgb), 1); color: rgba(var(--body-txt-rgb), 1); font-size: 13px;">' +
                '</div>';
        }

        // ---- Editor body ----
        var editorContentHtml;

        if (isHtml) {
            editorContentHtml =
                '<div class="plain-editor-shell">' +
                    '<div class="plain-pane plain-pane-input">' +
                        '<div class="plain-pane-label">Input</div>' +
                        buildToolbarHtml(prefix) +
                        '<textarea id="' + bodyId + '" class="plain-input" spellcheck="false"></textarea>' +
                    '</div>' +
                    '<div class="plain-pane plain-pane-preview">' +
                        '<div class="plain-preview-content" id="' + previewId + '"></div>' +
                    '</div>' +
                '</div>';
        } else {
            var editorStyle = 'min-height:' + rtfMinHeight + 'px;'
                + 'max-height:' + rtfMaxHeight + 'px;'
                + 'overflow-y:auto;'
                + 'background:#fff;'
                + 'border:1px solid rgba(0,0,0,0.15);'
                + 'border-radius:0 0 6px 6px;';
            editorContentHtml =
                buildToolbarHtml(prefix) +
                '<div id="' + bodyId + '" class="editable-area" contenteditable="true" style="' + editorStyle + '">' + initialHtml + '</div>';
        }

        // ---- Footer: help ? (HTML mode) + mode-switch link ----
        var footerHtml = '';
        if (!isEmail) {
            var modeLabel = isHtml
                ? 'In HTML Mode, click to change'
                : 'In Rich Text Mode, click to change';
            footerHtml =
                '<div id="' + uid + '_footer" style="margin-top: 12px; display: flex; align-items: center; gap: 10px;">' +
                    (isHtml
                        ? '<a href="javascript:void(0);" id="' + uid + '_help" class="plain-help-btn" title="HTML cheat sheet">?</a>'
                        : '') +
                    '<a href="javascript:void(0);" id="' + uid + '_switch" ' +
                    'style="font-size: 12px; color: rgba(var(--body-txt-rgb), 0.6); text-decoration: underline; cursor: pointer;">' +
                    modeLabel +
                    '</a>' +
                '</div>';
        }

        var contentHtml =
            '<div style="width: 100%; text-align: left;">' +
                emailFieldsHtml +
                editorContentHtml +
                (isEmail ? '<div id="' + statusId + '" style="margin-top: 8px; font-size: 12px; font-weight: bold; min-height: 16px;"></div>' : '') +
                footerHtml +
            '</div>';

        var buttons = isEmail
            ? [
                { text: 'Cancel', type: 'default', action: 'cancel' },
                { text: 'Send',   type: 'success', action: 'send'   }
              ]
            : [
                { text: 'Cancel', type: 'default', action: 'cancel' },
                { text: 'Apply',  type: 'success', action: 'apply'  }
              ];

        var defaultDimensions = isEmail ? '1000px, auto' : '750px, 750px';
        var dimensions = options.dimensions || defaultDimensions;

        showModalDialog(title, contentHtml, buttons, function(action) {
            var body = document.getElementById(bodyId);

            if (action === 'cancel') {
                if (typeof options.onCancel === 'function') options.onCancel();
                return;
            }

            if (action === 'apply' && !isEmail) {
                var html;
                if (isHtml) {
                    var ta = document.getElementById(bodyId);
                    html = textToBlockHtml(ta ? ta.value : '');
                } else {
                    html = body ? body.innerHTML : '';
                }
                if (typeof options.onApply === 'function') options.onApply(html);
                return;
            }

            if (action === 'send' && isEmail) {
                return sendEmail(bodyId, recipientsId, subjectId, statusId);
            }
        }, align, dimensions);

        // Cap the modal so it fits on smaller viewports.
        if (!isEmail) {
            setTimeout(function() {
                var modal = document.querySelector('#globalPortalModal > div');
                if (modal) {
                    modal.style.width     = '750px';
                    modal.style.height    = 'auto';
                    modal.style.maxWidth  = '95vw';
                    modal.style.maxHeight = '90vh';
                    modal.style.maxHeight = '90dvh';
                }
            }, 0);
        }

        // ---- Post-mount wiring ----
        setTimeout(function() {
            if (isHtml) {
                var ta = document.getElementById(bodyId);
                var pv = document.getElementById(previewId);
                if (!ta || !pv) return;
                var composing = false;

                function render() {
                    // Convert editable text into block HTML for previewing.
                    var withBlocks = textToBlockHtml(ta.value);
                    var sanitized = sanitizeHtmlForPreview(withBlocks);
                    pv.innerHTML = sanitized || '<span class="placeholder">Preview appears here as you type.</span>';
                }

                ta.addEventListener('compositionstart', function() { composing = true; });
                ta.addEventListener('compositionend', function() { composing = false; render(); });
                ta.addEventListener('input', function() { if (!composing) render(); });

                // Seed the textarea from initialHtml, converting block HTML
                // into editable text with newlines for line boundaries.
                ta.value = htmlToEditableText(initialHtml);
                render();
                ta.focus();
                ta.setSelectionRange(ta.value.length, ta.value.length);

                wireToolbarHtml(prefix, bodyId, TOOLBAR.length, render);

                var helpBtn = document.getElementById(uid + '_help');
                if (helpBtn) {
                    helpBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        openSyntaxHelp();
                    });
                }
            } else {
                setTimeout(function() { wireToolbarRtf(prefix, bodyId, TOOLBAR.length); }, 50);
            }

            // ---- Mode-switch link: lossless view toggle ----
            var switchEl = document.getElementById(uid + '_switch');
            if (switchEl) {
                switchEl.addEventListener('click', function() {
                    // Capture current content as HTML.
                    var currentHtml;
                    if (isHtml) {
                        var ta2 = document.getElementById(bodyId);
                        currentHtml = textToBlockHtml(ta2 ? ta2.value : '');
                    } else {
                        var b2 = document.getElementById(bodyId);
                        currentHtml = b2 ? b2.innerHTML : initialHtml;
                    }

                    // Persist preference.
                    try {
                        var empId = (window.__rtfEditorEmpId || 'anon');
                        localStorage.setItem('rtf_editor_format_' + empId, isHtml ? 'rtf' : 'html');
                    } catch (e) {}

                    // Close and reopen in the other mode with the same HTML.
                    var modal = document.getElementById('globalPortalModal');
                    if (modal) modal.remove();

                    window.openRtfEditor(Object.assign({}, options, {
                        format: isHtml ? 'rtf' : 'html',
                        initialHtml: currentHtml
                    }));
                });
            }
        }, 60);
    };

    // =====================================================================
    // Exports
    // =====================================================================
    window.isIOS = rtfIsIOS;
    window.htmlToPlainText = htmlToPlainText;
    window.sanitizeHtmlForPreview = sanitizeHtmlForPreview;

})();