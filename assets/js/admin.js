(function () {
  "use strict";

  var cfg = window.wcpdeAdmin || {};

  if (!cfg.restUrl || !cfg.nonce) {
    return;
  }

  var queueRunning = false;
  var queueCancelRequested = false;

  function isTableHtml(text) {
    return /<table[\s>]/i.test(text);
  }

  function emptyPreviewHtml() {
    return (
      '<p class="wcpde-table-preview__empty">' +
      (cfg.i18n.noPreview || "No preview") +
      "</p>"
    );
  }

  function setStatus(row, text, type) {
    var el = row.querySelector(".wcpde-status");
    if (!el) {
      return;
    }

    el.textContent = text || "";
    el.className = "wcpde-status" + (type ? " is-" + type : "");
  }

  function markDirty(row, dirty) {
    row.classList.toggle("is-dirty", !!dirty);
  }

  function getSavedExcerpt(row) {
    var hidden = row.querySelector('[data-field="excerpt"]');
    return hidden ? hidden.value : "";
  }

  function getDraftExcerpt(row) {
    var input = row.querySelector('[data-field="excerpt-input"]');
    return input ? input.value.trim() : "";
  }

  function getExcerptForSave(row) {
    var draft = getDraftExcerpt(row);
    if (draft !== "") {
      return draft;
    }

    return getSavedExcerpt(row);
  }

  function isRowSelected(row) {
    var checkbox = row.querySelector(".wcpde-row-select");
    return !!(checkbox && checkbox.checked);
  }

  function getSelectedRows() {
    return Array.prototype.filter.call(document.querySelectorAll(".wcpde-row"), isRowSelected);
  }

  function updatePreview(row, html) {
    var previewEl = row.querySelector(".wcpde-table-preview");
    if (!previewEl) {
      return;
    }

    if (html && isTableHtml(html)) {
      previewEl.innerHTML = html;
      previewEl.classList.remove("is-empty");
      return;
    }

    previewEl.innerHTML = emptyPreviewHtml();
    previewEl.classList.add("is-empty");
  }

  function syncInputAfterSave(row, excerpt) {
    var hidden = row.querySelector('[data-field="excerpt"]');
    var input = row.querySelector('[data-field="excerpt-input"]');

    if (hidden) {
      hidden.value = excerpt;
    }

    if (input) {
      input.value = isTableHtml(excerpt) ? "" : excerpt;
    }

    updatePreview(row, excerpt);
  }

  function setRowBusy(row, busy) {
    row.classList.toggle("is-busy", !!busy);

    var aiBtn = row.querySelector(".wcpde-ai-generate");
    var saveBtn = row.querySelector(".wcpde-save");
    var select = row.querySelector(".wcpde-row-select");

    if (aiBtn) {
      aiBtn.disabled = !!busy;
    }

    if (saveBtn) {
      saveBtn.disabled = !!busy;
    }

    if (select) {
      select.disabled = !!busy || queueRunning;
    }
  }

  function setSelectionDisabled(disabled) {
    var selectAll = document.querySelector(".wcpde-select-all");
    if (selectAll) {
      selectAll.disabled = !!disabled;
    }

    document.querySelectorAll(".wcpde-row-select").forEach(function (checkbox) {
      checkbox.disabled = !!disabled;
    });
  }

  function updateSelectAllState() {
    var selectAll = document.querySelector(".wcpde-select-all");
    var rowChecks = document.querySelectorAll(".wcpde-row-select");

    if (!selectAll || rowChecks.length === 0) {
      return;
    }

    var checkedCount = 0;

    rowChecks.forEach(function (checkbox) {
      if (checkbox.checked) {
        checkedCount += 1;
      }
    });

    selectAll.checked = checkedCount > 0 && checkedCount === rowChecks.length;
    selectAll.indeterminate = checkedCount > 0 && checkedCount < rowChecks.length;
  }

  function generateAiForRow(row) {
    var productId = row.getAttribute("data-product-id");
    var excerptText = getDraftExcerpt(row);

    if (!productId) {
      return Promise.reject(new Error(cfg.i18n.error || "Error"));
    }

    if (!excerptText) {
      return Promise.reject(new Error(cfg.i18n.aiNeedExcerpt || "Enter text first"));
    }

    if (isTableHtml(excerptText)) {
      return Promise.reject(new Error(cfg.i18n.aiNeedPlain || "Enter plain text first"));
    }

    setRowBusy(row, true);
    setStatus(row, cfg.i18n.aiLoading || "AI…", "loading");

    return fetch(cfg.aiUrl + productId + "/ai-excerpt", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": cfg.nonce,
      },
      body: JSON.stringify({ excerpt: excerptText }),
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.data || !result.data.success) {
          throw new Error(
            (result.data && result.data.message) || cfg.i18n.aiError || "AI error"
          );
        }

        var html = result.data.html || "";
        syncInputAfterSave(row, html);
        markDirty(row, true);
        return html;
      })
      .finally(function () {
        setRowBusy(row, false);
      });
  }

  function saveRow(row) {
    var productId = row.getAttribute("data-product-id");
    var excerpt = getExcerptForSave(row);

    if (!productId) {
      return Promise.reject(new Error(cfg.i18n.error || "Error"));
    }

    setRowBusy(row, true);
    setStatus(row, cfg.i18n.saving || "Saving…", "loading");

    return fetch(cfg.restUrl + productId, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": cfg.nonce,
      },
      body: JSON.stringify({
        excerpt: excerpt,
      }),
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.data || !result.data.success) {
          throw new Error(
            (result.data && result.data.message) || cfg.i18n.error || "Error"
          );
        }

        if (result.data.product) {
          syncInputAfterSave(row, result.data.product.excerpt || "");
        }

        markDirty(row, false);
        return result.data;
      })
      .finally(function () {
        setRowBusy(row, false);
      });
  }

  function getQueueRows() {
    return getSelectedRows().filter(function (row) {
      var text = getDraftExcerpt(row);
      return text !== "" && !isTableHtml(text);
    });
  }

  function setQueueUi(running, message) {
    var startBtn = document.querySelector(".wcpde-queue-start");
    var cancelBtn = document.querySelector(".wcpde-queue-cancel");
    var statusEl = document.querySelector(".wcpde-queue-status");

    if (startBtn) {
      startBtn.disabled = running || !cfg.aiReady;
    }

    if (cancelBtn) {
      cancelBtn.hidden = !running;
    }

    setSelectionDisabled(running);

    if (statusEl) {
      if (message) {
        statusEl.textContent = message;
        statusEl.hidden = false;
      } else if (!running) {
        statusEl.hidden = true;
        statusEl.textContent = "";
      }
    }
  }

  function formatProgress(current, total) {
    var template = cfg.i18n.queueProgress || "Processing %1$s of %2$s";
    return template.replace("%1$s", String(current)).replace("%2$s", String(total));
  }

  function formatDone(success, skipped, failed) {
    var template = cfg.i18n.queueDone || "Queue done";
    return template
      .replace("%1$s", String(success))
      .replace("%2$s", String(skipped))
      .replace("%3$s", String(failed));
  }

  function processQueue() {
    if (queueRunning || !cfg.aiReady) {
      return;
    }

    var selectedRows = getSelectedRows();

    if (selectedRows.length === 0) {
      setQueueUi(false, cfg.i18n.queueNoSelection || "Select products");
      window.setTimeout(function () {
        setQueueUi(false, "");
      }, 4000);
      return;
    }

    var rows = getQueueRows();

    if (rows.length === 0) {
      setQueueUi(false, cfg.i18n.queueEmpty || "No rows");
      window.setTimeout(function () {
        setQueueUi(false, "");
      }, 4000);
      return;
    }

    queueRunning = true;
    queueCancelRequested = false;
    setQueueUi(true, cfg.i18n.queueRunning || "Running…");

    var success = 0;
    var failed = 0;
    var index = 0;

    function next() {
      if (queueCancelRequested) {
        finish();
        return;
      }

      if (index >= rows.length) {
        finish();
        return;
      }

      var row = rows[index];
      var current = index + 1;

      setQueueUi(true, formatProgress(current, rows.length));
      row.classList.add("is-queue-active");

      generateAiForRow(row)
        .then(function () {
          return saveRow(row);
        })
        .then(function () {
          success += 1;
          setStatus(row, cfg.i18n.aiDone || "Done", "success");
        })
        .catch(function (error) {
          failed += 1;
          setStatus(row, error.message || cfg.i18n.aiError || "Error", "error");
        })
        .finally(function () {
          row.classList.remove("is-queue-active");
          index += 1;
          next();
        });
    }

    function finish() {
      queueRunning = false;
      var skipped = queueCancelRequested ? rows.length - index : 0;
      setQueueUi(false, formatDone(success, skipped, failed));
      window.setTimeout(function () {
        if (!queueRunning) {
          setQueueUi(false, "");
        }
      }, 6000);
    }

    next();
  }

  function bindRow(row) {
    var saveBtn = row.querySelector(".wcpde-save");
    var aiBtn = row.querySelector(".wcpde-ai-generate");
    var inputField = row.querySelector('[data-field="excerpt-input"]');
    var rowSelect = row.querySelector(".wcpde-row-select");
    var original = getExcerptForSave(row);

    if (rowSelect) {
      rowSelect.addEventListener("change", updateSelectAllState);
    }

    if (inputField) {
      inputField.addEventListener("input", function () {
        var draft = getDraftExcerpt(row);
        updatePreview(row, draft !== "" ? draft : getSavedExcerpt(row));
        markDirty(row, getExcerptForSave(row) !== original);
      });

      inputField.addEventListener("keydown", function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key === "Enter") {
          event.preventDefault();
          if (saveBtn) {
            saveBtn.click();
          }
        }
      });
    }

    if (aiBtn) {
      aiBtn.addEventListener("click", function () {
        if (queueRunning) {
          return;
        }

        if (!cfg.aiReady) {
          setStatus(row, cfg.i18n.aiNeedKey || "API key required", "error");
          return;
        }

        generateAiForRow(row)
          .then(function () {
            setStatus(row, cfg.i18n.aiDone || "AI done", "success");
          })
          .catch(function (error) {
            setStatus(row, error.message || cfg.i18n.aiError || "AI error", "error");
          });
      });
    }

    if (!saveBtn) {
      return;
    }

    saveBtn.addEventListener("click", function () {
      if (queueRunning) {
        return;
      }

      saveRow(row)
        .then(function () {
          original = getExcerptForSave(row);
          setStatus(row, cfg.i18n.saved || "Saved", "success");
        })
        .catch(function (error) {
          setStatus(row, error.message || cfg.i18n.error || "Error", "error");
        });
    });
  }

  document.querySelectorAll(".wcpde-row").forEach(bindRow);

  var selectAll = document.querySelector(".wcpde-select-all");
  if (selectAll) {
    selectAll.addEventListener("change", function () {
      var checked = selectAll.checked;
      document.querySelectorAll(".wcpde-row-select").forEach(function (checkbox) {
        checkbox.checked = checked;
      });
      selectAll.indeterminate = false;
    });
  }

  var queueStartBtn = document.querySelector(".wcpde-queue-start");
  var queueCancelBtn = document.querySelector(".wcpde-queue-cancel");

  if (queueStartBtn) {
    queueStartBtn.addEventListener("click", processQueue);
  }

  if (queueCancelBtn) {
    queueCancelBtn.addEventListener("click", function () {
      queueCancelRequested = true;
      setQueueUi(true, cfg.i18n.queueCancel || "Cancelling…");
    });
  }
})();
