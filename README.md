# TopUp Hub – game top-up store (CODM + Mobile Legends)

## Run in VS Code
1. File → Open Folder → `topup-store`
2. Terminal → New Terminal, then:
   ```
   npm install
   npm start
   ```
3. Open http://localhost:3000  (or press F5 – "Run Top-Up Store")

## Customize
- Games, packs, prices, payment methods: `server.js` (top of file)
- Look and feel: `public/style.css`
- Cover art/icons: `public/img/*.svg` (replace with your own licensed PNG/JPG, keep the same file names or update paths in `server.js`)
- Orders are saved to `orders.json`.

## Going live
Payments are simulated. In `server.js`, the `POST /api/orders` route has a TODO where you create a
PayMongo (GCash/Maya/card) checkout session and return its `checkout_url`. Add a webhook to mark
orders paid, then call your top-up supplier's API. You need the games' publisher/reseller
agreements before selling real credits, and official game art requires permission.
