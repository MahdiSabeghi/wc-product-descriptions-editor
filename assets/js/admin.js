(function () {
  "use strict";

  var cfg = window.wcpdeAdmin || {};

  if (!cfg.restUrl || !cfg.nonce) {
    return;
  }

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

  function bindRow(row) {
    var saveBtn = row.querySelector(".wcpde-save");
    var aiBtn = row.querySelector(".wcpde-ai-generate");
    var inputField = row.querySelector('[data-field="excerpt-input"]');
    var original = getExcerptForSave(row);
    var saving = false;
    var aiLoading = false;

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
        if (aiLoading || !cfg.aiReady) {
          if (!cfg.aiReady) {
            setStatus(row, cfg.i18n.aiNeedKey || "API key required", "error");
          }
          return;
        }

        var productId = row.getAttribute("data-product-id");
        if (!productId) {
          return;
        }

        var excerptText = getDraftExcerpt(row);

        if (!excerptText) {
          setStatus(row, cfg.i18n.aiNeedExcerpt || "Enter short description text first", "error");
          return;
        }

        if (isTableHtml(excerptText)) {
          setStatus(row, cfg.i18n.aiNeedPlain || "Enter plain text first", "error");
          return;
        }

        aiLoading = true;
        aiBtn.disabled = true;
        setStatus(row, cfg.i18n.aiLoading || "AI…", "loading");

        fetch(cfg.aiUrl + productId + "/ai-excerpt", {
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
            setStatus(row, cfg.i18n.aiDone || "AI done", "success");
          })
          .catch(function (error) {
            setStatus(row, error.message || cfg.i18n.aiError || "AI error", "error");
          })
          .finally(function () {
            aiLoading = false;
            aiBtn.disabled = false;
          });
      });
    }

    if (!saveBtn) {
      return;
    }

    saveBtn.addEventListener("click", function () {
      if (saving) {
        return;
      }

      var productId = row.getAttribute("data-product-id");
      if (!productId) {
        return;
      }

      var excerpt = getExcerptForSave(row);
      saving = true;
      saveBtn.disabled = true;
      setStatus(row, cfg.i18n.saving || "Saving…", "loading");

      fetch(cfg.restUrl + productId, {
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

          original = getExcerptForSave(row);
          markDirty(row, false);
          setStatus(row, cfg.i18n.saved || "Saved", "success");
        })
        .catch(function (error) {
          setStatus(row, error.message || cfg.i18n.error || "Error", "error");
        })
        .finally(function () {
          saving = false;
          saveBtn.disabled = false;
        });
    });
  }

  document.querySelectorAll(".wcpde-row").forEach(bindRow);
})();
