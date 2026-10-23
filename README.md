# Hellmerry's Vape Shop

A small PHP and SQLite inventory and sales dashboard for Hellmerry's Vape Shop. It tracks products, stock movements, sales, restock spending, and recent transactions in a responsive interface.

## Features

- Add, edit, search, and delete products.
- Record sales and restocks with stock validation and transaction history.
- View sales, restock spending, net balance, inventory cost value, and estimated markup profit.
- Review the last seven days of recorded sales and restocks.
- Compare weekly and calendar-month-to-date sales reports with transaction breakdowns.
- Review 30-day product performance and current stock storage levels.
- See a live local date and clock in the dashboard header.
- See low stock indicators for products with five units or fewer.
- Use the dashboard on desktop, tablet, and mobile screens.
- Protect state-changing forms with CSRF tokens and use prepared SQLite statements.

## Requirements

- PHP 7.4 or newer.
- PDO SQLite enabled in PHP.
- A web server or PHP's built-in development server.


 Visit [https://mpmp.freehosting.dev/index.php](https://mpmp.freehosting.dev/index.php).


## Financial definitions

- **Total sales** is the sum of recorded sale transactions.
- **Restock spending** is the sum of recorded restock transactions, calculated from quantity multiplied by the product's original cost at the time of restocking.
- **Net balance** is total sales minus restock spending. It is a cash movement summary, not accounting profit.
- **Inventory cost value** is current stock multiplied by each product's original cost.
- **Expected markup profit** estimates the markup on current stock. It is not realized profit.
- **Selling price** is original cost plus the configured markup percentage, rounded to cents.

The app displays amounts with a `$` symbol. Change the `money()` function in `Product.php` if you need a different currency display.

Product performance uses recorded sales from the previous 30 days: the top third of products with sales are marked **high**, other products with sales are **stable**, and products with no recorded sales are **low**. The storage analyzer marks more than five units as healthy stock, one to five as low stock, and zero as out of stock. Weekly reports cover Monday through today; monthly reports cover the current calendar month to date.

## Project files

- `Index.php` contains request handling and the server-rendered dashboard, styles, and small UI interactions.
- `Product.php` contains database initialization, validation, inventory operations, and dashboard queries.
