(function () {
  "use strict";

  var cfg = window.wcpdeAdmin || {};

  if (!cfg.restUrl || !cfg.nonce) {
    return;
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

  function snapshot(row) {
    var excerpt = row.querySelector('[data-field="excerpt"]');
    var content = row.querySelector('[data-field="content"]');

    return {
      excerpt: excerpt ? excerpt.value : "",
      content: content ? content.value : "",
    };
  }

  function bindRow(row) {
    var saveBtn = row.querySelector(".wcpde-save");
    var aiBtn = row.querySelector(".wcpde-ai-generate");
    var previewBtn = row.querySelector(".wcpde-ai-preview-toggle");
    var previewEl = row.querySelector(".wcpde-ai-preview");
    var fields = row.querySelectorAll(".wcpde-textarea, .wcpde-field");
    var original = snapshot(row);
    var saving = false;
    var aiLoading = false;

    function showPreview(html) {
      if (!previewEl) {
        return;
      }

      previewEl.innerHTML = html;
      previewEl.hidden = false;

      if (previewBtn) {
        previewBtn.hidden = false;
        previewBtn.setAttribute("aria-expanded", "true");
      }
    }

    function togglePreview() {
      if (!previewEl || !previewBtn) {
        return;
      }

      var open = previewBtn.getAttribute("aria-expanded") === "true";
      previewEl.hidden = open;
      previewBtn.setAttribute("aria-expanded", open ? "false" : "true");
    }

    fields.forEach(function (field) {
      field.addEventListener("input", function () {
        var current = snapshot(row);
        markDirty(
          row,
          current.excerpt !== original.excerpt || current.content !== original.content
        );
      });

      field.addEventListener("keydown", function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key === "Enter") {
          event.preventDefault();
          if (saveBtn) {
            saveBtn.click();
          }
        }
      });
    });

    if (previewBtn) {
      previewBtn.addEventListener("click", togglePreview);
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

        var excerptField = row.querySelector('[data-field="excerpt"]');
        var excerptText = excerptField ? excerptField.value.trim() : "";

        if (!excerptText) {
          setStatus(row, cfg.i18n.aiNeedExcerpt || "Enter short description text first", "error");
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

            var excerptField = row.querySelector('[data-field="excerpt"]');
            var html = result.data.html || "";

            if (excerptField) {
              excerptField.value = html;
            }

            showPreview(html);
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

      var current = snapshot(row);
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
          excerpt: current.excerpt,
          content: current.content,
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
            var excerptField = row.querySelector('[data-field="excerpt"]');
            var contentField = row.querySelector('[data-field="content"]');

            if (excerptField) {
              excerptField.value = result.data.product.excerpt || "";
            }
            if (contentField) {
              contentField.value = result.data.product.content || "";
            }
          }

          original = snapshot(row);
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
