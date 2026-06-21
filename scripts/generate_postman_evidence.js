const fs = require("fs");
const path = require("path");

const root = path.resolve(__dirname, "..");
const outputDir = path.join(root, "test-evidence", "full", "postman-pages");
const collection = require(path.join(
  root,
  "postman",
  "collections",
  "shop-quan-ao-admin-tests.postman_collection.json"
));
const run = require(path.join(
  root,
  "test-evidence",
  "full",
  "postman-run-full.json"
)).run;

const collectionUrl =
  "https://go.postman.co/collection/55518061-056fb80d-77ec-493a-9428-c454f607d8d6";
const runUrl =
  "https://go.postman.co/workspace/900b3729-79b8-4488-8057-db91c99a46a8/run/55518061-07996d52-0195-4919-93c5-24b2618b99ef";

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function flattenCollection() {
  const items = [];
  collection.item.forEach((folder) => {
    folder.item.forEach((item) => {
      items.push({ folder: folder.name, item });
    });
  });
  return items;
}

function getTestLines(item) {
  const testEvent = (item.event || []).find((event) => event.listen === "test");
  return testEvent?.script?.exec || [];
}

function getBody(item) {
  if (!item.request.body) return "";
  if (item.request.body.mode === "urlencoded") {
    return item.request.body.urlencoded
      .map((entry) => `${entry.key}=${entry.key === "password" ? "******" : entry.value}`)
      .join("&");
  }
  return item.request.body.raw || "";
}

function responseText(execution) {
  const stream = execution.response?.stream;
  if (!stream) return "";
  const buffer = Buffer.isBuffer(stream)
    ? stream
    : Buffer.from(stream.data || stream);
  return buffer.toString("utf8");
}

function executionUrl(execution) {
  const url = execution.request.url;
  const base = `${url.protocol || "http"}://${(url.host || []).join(".")}${
    url.port ? `:${url.port}` : ""
  }/${(url.path || []).join("/")}`;
  const query = (url.query || [])
    .filter((entry) => !entry.disabled)
    .map(
      (entry) =>
        `${encodeURIComponent(entry.key)}=${encodeURIComponent(entry.value ?? "")}`
    )
    .join("&");
  return query ? `${base}?${query}` : base;
}

function pageTemplate(title, content) {
  return `<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>${escapeHtml(title)}</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #f5f5f5; color: #212121; font-family: Inter, "Segoe UI", Arial, sans-serif; }
    header { height: 58px; background: #212121; color: white; display: flex; align-items: center; padding: 0 28px; }
    .brand { color: #ff6c37; font-weight: 800; font-size: 22px; margin-right: 16px; }
    .title { font-weight: 650; }
    .badge { margin-left: auto; border: 1px solid #777; border-radius: 4px; padding: 5px 9px; font-size: 12px; }
    main { padding: 20px 28px 28px; }
    .source { font-size: 12px; color: #555; margin-bottom: 14px; overflow-wrap: anywhere; }
    .grid { display: grid; grid-template-columns: 300px 1fr; gap: 18px; }
    .panel { background: white; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 1px 3px #00000012; overflow: hidden; }
    .panel h2 { margin: 0; padding: 13px 16px; font-size: 15px; border-bottom: 1px solid #e4e4e4; background: #fafafa; }
    .folder { padding: 9px 14px; font-weight: 700; font-size: 13px; background: #f1f1f1; }
    .request-row { padding: 8px 14px; border-top: 1px solid #eee; font-size: 12px; display: flex; gap: 8px; align-items: center; }
    .request-row.active { background: #fff1eb; border-left: 4px solid #ff6c37; padding-left: 10px; }
    .method { font-weight: 800; color: #067647; min-width: 36px; }
    .method.POST { color: #9c2fba; }
    .content { padding: 16px; }
    .request-line { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; }
    .method-pill { background: #e9f8f0; color: #067647; font-weight: 800; border-radius: 4px; padding: 8px 12px; }
    .method-pill.POST { color: #8b2bb0; background: #f7eafb; }
    .url { flex: 1; border: 1px solid #ccc; border-radius: 4px; padding: 9px 12px; font-family: Consolas, monospace; font-size: 13px; }
    .status { border-radius: 4px; padding: 8px 12px; font-weight: 800; color: white; background: #0a8f45; }
    .status.failed { background: #cf2e2e; }
    .tabs { display: flex; gap: 18px; border-bottom: 1px solid #ddd; margin-bottom: 12px; }
    .tab { padding: 8px 2px; font-weight: 700; font-size: 13px; }
    .tab.active { color: #e6531f; border-bottom: 3px solid #ff6c37; }
    pre { margin: 0; background: #1e1e1e; color: #e8e8e8; border-radius: 5px; padding: 12px; font: 12px/1.35 Consolas, monospace; max-height: 205px; overflow: hidden; white-space: pre-wrap; }
    .assertions { margin-top: 13px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .assertion { border: 1px solid #ddd; border-radius: 5px; padding: 9px 11px; font-size: 12px; }
    .assertion.pass { border-left: 5px solid #16a34a; }
    .assertion.fail { border-left: 5px solid #dc2626; background: #fff5f5; }
    .assertion strong { display: block; margin-bottom: 3px; }
    .summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
    .metric { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 14px; }
    .metric b { display: block; font-size: 28px; margin-top: 4px; }
    .pass-text { color: #16a34a; }
    .fail-text { color: #dc2626; }
    table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #ddd; }
    th, td { border-bottom: 1px solid #e5e5e5; padding: 9px 12px; text-align: left; font-size: 12px; }
    th { background: #fafafa; }
  </style>
</head>
<body>
  <header>
    <div class="brand">POSTMAN</div>
    <div class="title">${escapeHtml(collection.info.name)}</div>
    <div class="badge">Collection UID: 55518061-056fb80d-77ec-493a-9428-c454f607d8d6</div>
  </header>
  <main>${content}</main>
</body>
</html>`;
}

const flattened = flattenCollection();
const stats = run.stats.assertions;

const overviewRows = flattened
  .map(({ folder, item }, index) => {
    const execution = run.executions[index];
    const failed = (execution.assertions || []).filter((assertion) => assertion.error).length;
    const passed = (execution.assertions || []).length - failed;
    return `<tr>
      <td>${index + 1}</td>
      <td>${escapeHtml(folder)}</td>
      <td><strong>${escapeHtml(item.name)}</strong></td>
      <td>${escapeHtml(item.request.method)}</td>
      <td>${execution.response.code}</td>
      <td class="${failed ? "fail-text" : "pass-text"}">${passed} đạt / ${failed} lỗi</td>
    </tr>`;
  })
  .join("");

const overview = pageTemplate(
  "Postman Collection Overview",
  `<div class="source"><strong>Nguồn Postman Cloud:</strong> ${escapeHtml(collectionUrl)}<br><strong>Run:</strong> ${escapeHtml(runUrl)}</div>
  <div class="summary">
    <div class="metric">Request đã chạy<b>${run.stats.requests.total}</b></div>
    <div class="metric">Tổng assertion<b>${stats.total}</b></div>
    <div class="metric pass-text">Assertion đạt<b>${stats.total - stats.failed}</b></div>
    <div class="metric fail-text">Assertion lỗi<b>${stats.failed}</b></div>
  </div>
  <table>
    <thead><tr><th>STT</th><th>Folder</th><th>Testcase</th><th>Method</th><th>HTTP</th><th>Kết quả</th></tr></thead>
    <tbody>${overviewRows}</tbody>
  </table>`
);

fs.mkdirSync(outputDir, { recursive: true });
fs.writeFileSync(path.join(outputDir, "00-collection-overview.html"), overview);

flattened.forEach(({ folder, item }, index) => {
  const execution = run.executions[index];
  const assertions = execution.assertions || [];
  const failed = assertions.some((assertion) => assertion.error);
  const sidebar = collection.item
    .map((collectionFolder) => {
      const rows = collectionFolder.item
        .map((requestItem) => {
          const active = requestItem.name === item.name ? " active" : "";
          return `<div class="request-row${active}">
            <span class="method ${requestItem.request.method}">${escapeHtml(requestItem.request.method)}</span>
            <span>${escapeHtml(requestItem.name)}</span>
          </div>`;
        })
        .join("");
      return `<div class="folder">${escapeHtml(collectionFolder.name)}</div>${rows}`;
    })
    .join("");
  const testCode = getTestLines(item).join("\n");
  const body = getBody(item);
  const response = responseText(execution)
    .replace(/<style[\s\S]*?<\/style>/gi, "")
    .replace(/<[^>]+>/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, 550);
  const assertionCards = assertions
    .map(
      (assertion) => `<div class="assertion ${assertion.error ? "fail" : "pass"}">
        <strong>${assertion.error ? "FAIL" : "PASS"} - ${escapeHtml(assertion.assertion)}</strong>
        ${assertion.error ? escapeHtml(assertion.error.message) : "Assertion đạt như mong đợi."}
      </div>`
    )
    .join("");
  const content = `<div class="source"><strong>Postman Cloud collection:</strong> ${escapeHtml(collectionUrl)}<br><strong>Postman Cloud run:</strong> ${escapeHtml(runUrl)}</div>
    <div class="grid">
      <section class="panel"><h2>Collection</h2>${sidebar}</section>
      <section class="panel">
        <h2>${escapeHtml(folder)} / ${escapeHtml(item.name)}</h2>
        <div class="content">
          <div class="request-line">
            <span class="method-pill ${item.request.method}">${escapeHtml(item.request.method)}</span>
            <div class="url">${escapeHtml(executionUrl(execution))}</div>
            <span class="status ${failed ? "failed" : ""}">HTTP ${execution.response.code} - ${failed ? "FAILED" : "PASSED"}</span>
          </div>
          <div class="tabs"><div class="tab active">Tests</div><div class="tab">Body</div><div class="tab">Response</div></div>
          <pre>${escapeHtml(testCode)}${body ? `\n\nRequest body:\n${escapeHtml(body)}` : ""}</pre>
          <div class="assertions">${assertionCards}</div>
          <div style="margin-top:10px;font-size:12px;color:#555;"><strong>Response preview:</strong> ${escapeHtml(response || "(không có nội dung)")}</div>
        </div>
      </section>
    </div>`;

  const fileName = `${String(index + 1).padStart(2, "0")}-${item.name
    .split(" - ")[0]
    .toLowerCase()}.html`;
  fs.writeFileSync(path.join(outputDir, fileName), pageTemplate(item.name, content));
});

console.log(`Generated ${flattened.length + 1} Postman evidence pages in ${outputDir}`);
