const app = document.getElementById("app");

const peso = n =>
  "₱" + Number(n).toLocaleString("en-PH", {
    minimumFractionDigits: Number.isInteger(n) ? 0 : 2,
    maximumFractionDigits: 2
  });
const peso2 = n =>
  "₱" + Number(n).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const packLabel = (g, p) =>
  (typeof p.amount === "number" ? p.amount.toLocaleString() + " " + g.currency : p.amount) +
  (p.note ? ` (${p.note})` : "");
const packName = (g, p) =>
  typeof p.amount === "number" ? p.amount.toLocaleString() + " " + g.currency : p.amount;

let DATA = null;

async function init() {
  DATA = await (await fetch("/api/games")).json();
  window.addEventListener("hashchange", route);

  // Coming back from the payment page: show the order status
  const back = new URLSearchParams(location.search).get("order");
  if (back) history.replaceState(null, "", "/#/receipt/" + back);

  const how = document.querySelector("[data-scroll]");
  if (how) {
    how.addEventListener("click", () =>
      setTimeout(() => document.getElementById("how")?.scrollIntoView({ behavior: "smooth" }), 50)
    );
  }
  route();
}

let pollTimer = null;

function route() {
  window.scrollTo(0, 0);
  clearInterval(pollTimer); // stop the receipt auto-refresh when leaving the page

  const rc = location.hash.match(/^#\/receipt\/([\w-]+)/);
  if (rc) return renderReceipt(rc[1]);

  const t = location.hash.match(/^#\/track\/?([\w-]*)/);
  if (t) return renderTrack(t[1]);

  const m = location.hash.match(/^#\/game\/(\w+)/);
  if (m) return renderGame(DATA.games.find(g => g.id === m[1]));

  renderHome();
}

function renderHome() {
  const tile = g => `
    <a class="tile" href="#/game/${g.id}">
      <img src="${g.cover}" alt="${g.name} cover">
      <div>${g.name}<small>${g.publisher}</small></div>
    </a>`;
  const games = DATA.games.filter(g => g.category !== "load");
  const loads = DATA.games.filter(g => g.category === "load");

  app.innerHTML = `
    <section class="hero">
      <div>
        <h1>Game credits, delivered in minutes.</h1>
        <p>Top up Call of Duty: Mobile CP and Mobile Legends Diamonds. Pay with GCash, Maya, card, 7-Eleven, or your Globe, TM, Smart load.</p>
      </div>
    </section>

    <h2>Choose your game</h2>
    <div class="grid">${games.map(tile).join("")}</div>

    ${loads.length ? `
    <h2>Mobile load</h2>
    <div class="grid">${loads.map(tile).join("")}</div>` : ""}

    <h2 id="how">How it works</h2>
    <div class="how">
      <div><b>Enter your account</b><span>Add your game ID. We never ask for your password.</span></div>
      <div><b>Pick an amount</b><span>Choose a pack and a payment method.</span></div>
      <div><b>Pay and play</b><span>Credits land in your account after payment.</span></div>
    </div>`;
}

function renderGame(g) {
  if (!g) { location.hash = "#/"; return; }

  app.innerHTML = `
  <div class="game">
    <aside class="side">
      <img src="${g.cover}" alt="">
      <div><h1>${g.name}</h1><p>${g.publisher}</p></div>
    </aside>

    <form id="f" novalidate>
      <section class="step"><h3><span class="n">1</span>Enter your account</h3>
        <div class="fields">${g.fields.map(x => `
          <label class="f">${x.label}
            <input type="text" inputmode="numeric" name="${x.key}" placeholder="${x.placeholder}" autocomplete="off">
          </label>`).join("")}
        </div>
      </section>

      <section class="step"><h3><span class="n">2</span>Select amount</h3>
        <div class="opts">${g.packs.map(p => `
          <label class="opt">
            <input type="radio" name="pack" value="${p.id}">
            <span class="card">
              <span class="amt"><img src="${g.icon}" alt="">${packName(g, p)}</span>
              ${p.note ? `<small class="bonus">(${p.note})</small>` : ""}
              <span class="price">${peso(p.price)}</span>
            </span>
          </label>`).join("")}
        </div>
      </section>

      <section class="step"><h3><span class="n">3</span>Checkout</h3>
        <div class="fields">
          <input type="email" name="email" placeholder="Email for your receipt (optional)" autocomplete="email" aria-label="Email (optional)">
        </div>
        <div class="buy">
          <div class="total"><span id="sum">Select an amount</span><b id="total">${peso2(0)}</b></div>
          <button class="cta" type="submit" id="go">Checkout</button>
        </div>
      </section>

      <p class="err" id="err" role="alert"></p>
    </form>
  </div>`;

  const f = document.getElementById("f");
  const err = document.getElementById("err");

  const readAccount = () => {
    const a = {};
    g.fields.forEach(x => (a[x.key] = f[x.key].value.trim()));
    return a;
  };
  const pickedPack = () =>
    g.packs.find(p => p.id === f.querySelector("[name=pack]:checked")?.value);

  f.addEventListener("change", () => {
    const p = pickedPack();
    if (!p) return;
    document.getElementById("sum").textContent = packLabel(g, p);
    document.getElementById("total").textContent = peso2(p.price);
  });

  f.addEventListener("submit", async e => {
    e.preventDefault();
    err.textContent = "";
    try {
      const account = readAccount();
      const bad = g.fields.find(
        x => !new RegExp(x.pattern || "^\\d{4,20}$").test(account[x.key])
      );
      if (bad) throw new Error(`Enter a valid ${bad.label} (${bad.hint || "numbers only"}).`);

      const pack = pickedPack();
      if (!pack) throw new Error("Select an amount.");

      const mail = f.email.value.trim();
      if (mail && !/^\S+@\S+\.\S+$/.test(mail)) {
        throw new Error("That email doesn't look right. You can leave it blank.");
      }

      const r = await fetch("/api/quotes", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ gameId: g.id, packId: pack.id, account })
      });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error);

      showConfirm({ g, pack, account, quotes: j.quotes, nickname: j.nickname, email: mail });
    } catch (ex) {
      err.textContent = ex.message;
    }
  });
}

function showConfirm({ g, pack, account, quotes, nickname, email }) {
  const id = (account.userId || account.mobile) + (account.zoneId ? ` (${account.zoneId})` : "");
  let sel = DATA.payments[0].id;

  const m = document.createElement("div");
  m.className = "overlay";
  m.innerHTML = `
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mt">
      <button class="x" aria-label="Close">×</button>
      <h2 id="mt">Order Details</h2>

      <div class="item">
        <img src="${g.icon}" alt="">
        <div class="iname"><b>${packLabel(g, pack)}</b><span class="price">${peso2(pack.price)}</span></div>
      </div>

      <div class="acct">
        <span class="lbl">Account info</span>
        <b>${id}</b>${nickname ? `<span class="nick">${nickname}</span>` : ""}
      </div>

      <p class="sect">SELECT PAYMENT</p>
      <div class="opts pay">${DATA.payments.map((p, i) => `
        <label class="opt">
          <input type="radio" name="mpay" value="${p.id}" ${i === 0 ? "checked" : ""}>
          <span class="card"><b>${p.name}</b>${p.mobile ? `<small>Charge to SIM load</small>` : ""}<span class="price">${peso2(quotes[p.id].total)}</span></span>
        </label>`).join("")}
      </div>

      <div class="simbox" id="simbox" hidden>
        <label class="f">Mobile number to charge
          <input type="text" id="payer" inputmode="numeric" placeholder="e.g. 09171234567" autocomplete="tel">
        </label>
        <p class="muted small">The amount is taken from this number's prepaid load balance.</p>
      </div>

      <p class="err" id="merr" role="alert"></p>

      <div class="mfoot">
        <div class="total">Total Payment<b id="mtot">${peso2(quotes[sel].total)}</b></div>
        <button class="cta" id="ok">Checkout with ${DATA.payments[0].name}</button>
      </div>
    </div>`;
  document.body.appendChild(m);

  const ok = m.querySelector("#ok");

  m.addEventListener("change", e => {
    if (e.target.name !== "mpay") return;
    sel = e.target.value;
    const pay = DATA.payments.find(p => p.id === sel);
    m.querySelector("#mtot").textContent = peso2(quotes[sel].total);
    m.querySelector("#simbox").hidden = !pay.mobile;
    ok.textContent = "Checkout with " + pay.name;
  });

  const onKey = e => e.key === "Escape" && close();
  const close = () => {
    m.remove();
    document.removeEventListener("keydown", onKey);
  };
  document.addEventListener("keydown", onKey);
  m.querySelector(".x").onclick = close;
  m.addEventListener("click", e => e.target === m && close());

  ok.onclick = async () => {
    const pay = DATA.payments.find(p => p.id === sel);
    const payerMobile = m.querySelector("#payer").value.trim();
    if (pay.mobile && !/^09\d{9}$/.test(payerMobile)) {
      m.querySelector("#merr").textContent =
        "Enter the mobile number to charge (11 digits, starts with 09).";
      return;
    }
    m.querySelector("#merr").textContent = "";

    ok.disabled = true;
    ok.textContent = "Processing…";
    try {
      const r = await fetch("/api/orders", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ gameId: g.id, packId: pack.id, paymentId: sel, account, email, payerMobile })
      });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error);
      close();
      location.href = "/payment.html?order=" + j.order.id;
    } catch (ex) {
      m.querySelector("#merr").textContent = ex.message;
      ok.disabled = false;
      ok.textContent = "Checkout with " + DATA.payments.find(p => p.id === sel).name;
    }
  };
  ok.focus();
}

// One place for how each order status is shown to the customer
function statusInfo(s) {
  switch (s) {
    case "paid":
    case "paid_demo":
      return { label: "Paid", badge: "PAID", cls: "ok", steps: ["done", "done", "done"],
        note: "Payment confirmed. Your credits are being sent to your account." };
    case "awaiting_confirmation":
      return { label: "Checking your payment", badge: "CHECKING PAYMENT", cls: "wait", steps: ["done", "now", "todo"],
        note: "We got your message and we're checking your payment. This page updates by itself, so you can keep it open." };
    case "rejected":
      return { label: "Payment not found", badge: "NOT CONFIRMED", cls: "bad", steps: ["done", "bad", "todo"],
        note: "We could not find your payment. If you already paid, message us with your order number." };
    default:
      return { label: "Waiting for payment", badge: "WAITING FOR PAYMENT", cls: "wait", steps: ["done", "todo", "todo"],
        note: "We have not received your payment yet. Send it, then tap \"I've sent the payment\"." };
  }
}

const isOpen = s => s === "pending_payment" || s === "awaiting_confirmation";

function stepper(steps) {
  const names = ["Order placed", "Payment check", "Paid"];
  return `<ol class="track">${names.map((n, i) =>
    `<li class="${steps[i]}"><span class="dot">${steps[i] === "done" ? "✓" : steps[i] === "bad" ? "!" : i + 1}</span>${n}</li>`
  ).join("")}</ol>`;
}

// "Congratulations" popup. Shows once per order, the first time the customer sees it as paid.
function celebrate(o) {
  const key = "paid-seen-" + o.id;
  try {
    if (localStorage.getItem(key)) return;
    localStorage.setItem(key, "1");
  } catch (e) { /* storage blocked: the popup just shows again next time */ }

  const m = document.createElement("div");
  m.className = "overlay";
  m.innerHTML = `
    <div class="modal congrats" role="dialog" aria-modal="true" aria-labelledby="ct">
      <div class="bigcheck">✓</div>
      <h2 id="ct">Congratulations!</h2>
      <p>Your payment is confirmed. We'll send your <b>${o.item || o.pack}</b> to your account shortly.</p>
      <p class="muted small">Order ${o.id}</p>
      <button class="cta" id="cgo">View receipt</button>
    </div>`;
  document.body.appendChild(m);

  const onKey = e => e.key === "Escape" && close();
  const close = () => {
    m.remove();
    document.removeEventListener("keydown", onKey);
  };
  document.addEventListener("keydown", onKey);
  m.addEventListener("click", e => e.target === m && close());
  m.querySelector("#cgo").onclick = close;
  m.querySelector("#cgo").focus();
}

async function renderReceipt(id) {
  app.innerHTML = `<section class="receipt"><p>Loading your order…</p></section>`;

  try {
    const r = await fetch("/api/orders/" + encodeURIComponent(id));
    const j = await r.json();
    if (!r.ok) throw new Error(j.error);

    const o = j.order;
    const paid = o.status === "paid" || o.status === "paid_demo";
    const st = statusInfo(o.status);
    const when = new Date(o.paidAt || o.reportedAt || o.createdAt).toLocaleString("en-PH", {
      dateStyle: "medium",
      timeStyle: "short"
    });
    const money = v => (v === undefined ? "—" : peso2(v));

    app.innerHTML = `
      <section class="receipt paper">
        <div class="rhead">
          <div class="brand"><span class="logo">T</span>TopUp Ulan</div>
          <span class="badge ${st.cls}">${st.badge}</span>
        </div>

        <h1>${paid ? "Receipt" : "Order status"}</h1>
        <p class="muted">${when} · Order ${o.id}</p>

        ${stepper(st.steps)}

        <dl>
          <dt>${o.accountLabel || "Account ID"}</dt><dd>${o.accountId || o.accountMasked}</dd>
          <dt>Game</dt><dd>${o.game}</dd>
          <dt>Item</dt><dd>${o.item || (typeof o.pack === "number" ? o.pack.toLocaleString() : o.pack)}</dd>
          <dt>Payment method</dt><dd>${o.payment}${o.payerMasked ? ` (${o.payerMasked})` : ""}</dd>
        </dl>

        <dl class="sum">
          <dt>Price</dt><dd>${money(o.price)}</dd>
          ${o.fee ? `<dt>Service fee</dt><dd>${money(o.fee)}</dd>` : ""}
          ${o.vat ? `<dt>VAT</dt><dd>${money(o.vat)}</dd>` : ""}
          <dt class="tot">Total</dt><dd class="tot">${peso2(o.total)}</dd>
        </dl>

        <p class="muted small">${st.note}</p>

        <div class="ractions no-print">
          ${o.status === "pending_payment"
            ? `<a class="cta btnlink" href="/payment.html?order=${encodeURIComponent(o.id)}">Pay now</a>`
            : paid ? `<button class="cta" id="pr">Print / Save as PDF</button>` : ""}
          <button class="ghost" id="cp">Copy order no.</button>
          <a href="#/">Back to games</a>
        </div>
      </section>`;

    const pr = document.getElementById("pr");
    if (pr) pr.onclick = () => window.print();
    document.getElementById("cp").onclick = e => {
      navigator.clipboard?.writeText(o.id).then(() => (e.target.textContent = "Copied!"));
    };

    if (paid) celebrate(o);

    // While the order is still open, check every 8 seconds and redraw when the status changes
    clearInterval(pollTimer);
    if (isOpen(o.status)) {
      pollTimer = setInterval(async () => {
        try {
          const rr = await fetch("/api/orders/" + encodeURIComponent(id), { cache: "no-store" });
          const jj = await rr.json();
          if (rr.ok && jj.order.status !== o.status) {
            clearInterval(pollTimer);
            renderReceipt(id);
          }
        } catch (e) { /* offline for a moment: try again next time */ }
      }, 8000);
    }
  } catch (ex) {
    app.innerHTML = `
      <section class="receipt">
        <h1 style="color:inherit">Order not found</h1>
        <p>${ex.message}</p>
        <a href="#/">Back to games</a>
      </section>`;
  }
}

function renderTrack(id) {
  app.innerHTML = `
    <section class="receipt">
      <h1 style="color:inherit">Track your order</h1>
      <form id="tf" class="fields">
        <input type="text" name="oid" placeholder="e.g. TU-MVKXTADO"
               value="${id}" aria-label="Order ID" autocomplete="off">
        <button class="cta" type="submit">Find order</button>
      </form>
      <p class="err" id="terr" role="alert"></p>
      <div id="tres"></div>
    </section>`;

  const form = document.getElementById("tf");
  form.addEventListener("submit", e => {
    e.preventDefault();
    location.hash = "#/track/" + form.elements.oid.value.trim().toUpperCase();
  });

  if (!id) return;

  fetch("/api/orders/" + encodeURIComponent(id))
    .then(async r => {
      const j = await r.json();
      if (!r.ok) throw new Error(j.error);
      const o = j.order;
      const status = statusInfo(o.status).label;
      document.getElementById("tres").innerHTML = `
        <dl>
          <dt>Order</dt><dd>${o.id}</dd>
          <dt>Game</dt><dd>${o.game}</dd>
          <dt>Amount</dt><dd>${typeof o.pack === "number" ? o.pack.toLocaleString() : o.pack}</dd>
          <dt>Payment</dt><dd>${o.payment}</dd>
          <dt>Total</dt><dd>${peso2(o.total)}</dd>
          <dt>Status</dt><dd>${status}</dd>
        </dl>`;
    })
    .catch(ex => (document.getElementById("terr").textContent = ex.message));
}

init();