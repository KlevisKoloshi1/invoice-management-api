# Invoice Management API

A comprehensive Laravel-based invoice management system with fiscalization capabilities, import functionality, and web interface.

## Features

- **Invoice Management**: Create, edit, and manage invoices with items
- **Client Management**: Store and manage client information
- **Excel Import**: Import invoice data from Excel files with flexible format support
- **Fiscalization**: Automatic fiscalization of invoices (Albanian tax system)
- **Web Interface**: User-friendly web interface for managing invoices
- **Authentication**: Built-in user authentication and authorization
- **API Endpoints**: RESTful API for programmatic access

## Requirements

- PHP 8.1 or higher
- Laravel 10.x
- MySQL/PostgreSQL database
- Composer
- Node.js (for frontend assets)

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/invoice-management-api.git
   cd invoice-management-api
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install Node.js dependencies**
   ```bash
   npm install
   ```

4. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. **Configure database**
   Edit `.env` file with your database credentials:
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=invoice_management
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

6. **Run migrations**
   ```bash
   php artisan migrate
   ```

7. **Seed the database (optional)**
   ```bash
   php artisan db:seed
   ```

8. **Start the development server**
   ```bash
   php artisan serve
   ```

## Usage

### Web Interface
- Access the application at `http://localhost:8000`
- Register a new account or login
- Navigate to "Imports" to upload Excel files
- View and manage invoices in the "Invoices" section

### API Endpoints

#### Authentication
- `POST /api/register` - Register a new user
- `POST /api/login` - Login user
- `POST /api/logout` - Logout user

#### Invoices
- `GET /api/invoices` - List all invoices
- `POST /api/invoices` - Create a new invoice
- `GET /api/invoices/{id}` - Get invoice details
- `PUT /api/invoices/{id}` - Update invoice
- `DELETE /api/invoices/{id}` - Delete invoice

#### Imports
- `GET /api/imports` - List all imports
- `POST /api/imports` - Upload and process Excel file
- `GET /api/imports/{id}` - Get import details

## Full Fiscalization Excel Import Format

The system supports a comprehensive Excel format for fiscalization, matching the requirements of the Albanian tax system and 2RM Lab exports. Each row represents one invoice item. For invoices with multiple items, repeat the invoice columns and change only the item columns.

**Required headers (order can vary):**

```
invoice_number, invoice_date, business_unit, issuer_tin, invoice_type, is_e_invoice, operator_code, software_code, payment_method, total_amount, total_before_vat, vat_amount, vat_rate, buyer_name, buyer_address, buyer_tax_number, customer_id, city_id, automatic_payment_method_id, currency_id, cash_register_id, fiscal_invoice_type_id, fiscal_profile_id, item_name, item_quantity, item_price, item_vat_rate, item_total_before_vat, item_vat_amount, unit, item_unit_id, tax_rate_id, item_id, item_type_id, item_code, warehouse_id
```

**Sample row:**
```
FSH10001, 20/07/2025 19:12, BU001, L8131000K5, Cash Invoice, No, OP001, SW001, Banknotes and coins, 2050, 1708.33, 341.67, 20, Test Client, Tirana, SKA, 1, 1, 0, 1, 9, 4, 1, artUPDATED, 10, 205, 20, 1708.33, 341.67, cope, 1, 2, 3, 1, art1, 5
```

- **Date format:** `DD/MM/YYYY HH:mm` (e.g., `20/07/2025 19:12`)
- **Booleans:** Use `Yes`/`No` or `1`/`0` for fields like `is_e_invoice`.
- **IDs:** Use your actual system IDs for fields like `customer_id`, `city_id`, `item_unit_id`, `tax_rate_id`, `item_id`, `item_type_id`, `warehouse_id`.
- **For multi-item invoices:** Repeat the invoice columns and change only the item columns for each item.

### Troubleshooting Fiscalization Import Errors

- If you see errors like `Gabim ne nisjen e llojit te artikullit! (A3)`, check that the item is registered and active in the warehouse in your fiscalization system, and that the item type is correct.
- Make sure all required columns are present and filled.
- If you add new columns to the database, run migrations: `php artisan migrate`.

## Legacy Excel Formats (for reference)

The system previously supported the following formats, but the full fiscalization format above is now recommended for all new imports.

### Standard Format
Headers: `client_name`, `client_email`, `client_address`, `client_phone`, `invoice_total`, `invoice_status`, `item_description`, `item_quantity`, `item_price`, `item_total`

### Custom Report Format
Automatically detects columns with Albanian headers:
- `pershkrimi` (description)
- `njesia` (unit)
- `sasia` (quantity)
- `cmimi` (price)

---

## Supported 2RM Lab Excel Format

This application is designed to import real-world Excel files exported from 2RM Lab (Albanian sales software), including all the quirks of their report format:

- Multiple invoice blocks per file
- Metadata and blank rows at the top or between invoices
- Blank rows between invoice blocks and item headers
- Item headers in any order, with or without extra columns
- Albanian characters and accents in headers
- No need to manually reformat the exported file

**Each invoice block must contain:**
- A line with both `Nr:` and `Date dokumenti:`
- A client info line (e.g., `Klienti: ...`, `Emri: ...`, `NIPT: ...`)
- An item header row with at least: `Përshkrimi`, `Sasia`, `Çmimi` (unit is optional)
- Item rows, followed by a summary row (e.g., `Shuma`)

**Example:**
```
RAPORT SHITJE
Data: 01.07.2024

Nr: 12345        Date dokumenti: 01.07.2024
Klienti: 001     Emri: John Doe     NIPT: L12345678A

Përshkrimi       Njësia     Sasia     Çmimi     Totali
Ujë              copë       2         100       200
Bukë             copë       1         50        50
Shuma                                   250

Nr: 12346        Date dokumenti: 01.07.2024
Klienti: 002     Emri: Jane Smith     NIPT: L87654321B

Përshkrimi       Njësia     Sasia     Çmimi     Totali
Kafe             copë       3         80        240
Çaj              copë       2         60        120
Shuma                                   360
```

**Instructions:**
- Do not edit or reformat the exported file. The importer is designed to work with the file as exported from 2RM Lab.
- Save as `.xlsx` (not `.csv` or `.xls`).
- Upload the file using the import form.

## Troubleshooting Import Errors

- If you see errors about "item header not found," check that the item header row is present, not merged, and each header is in its own cell.
- If you see "no invoices detected," ensure each invoice block starts with `Nr:` and `Date dokumenti:`.
- The importer is robust to blank rows, extra columns, and header order. If you encounter issues, check the Laravel log (`storage/logs/laravel.log`) for details on what the parser is seeing.

## Fiscalization

The system automatically fiscalizes invoices using the Albanian tax system. Ensure your fiscalization service is properly configured in the environment variables.

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For support, email support@example.com or create an issue in the GitHub repository.

## API Documentation

### Authentication
All endpoints require authentication as an admin user unless otherwise specified.

---

### 1. Upload Import (Create)
**POST** `/api/imports`

- **Description:** Upload and import invoices from an Excel file (admin only).
- **Request:**
  - Content-Type: `multipart/form-data`
  - Body:
    - `file`: Excel file (.xlsx, .xls)
- **Success Response:**
  - Status: `201 Created`
  - Body:
    ```json
    {
      "import_id": 1,
      "success_count": 2,
      "errors": [],
      "invoice_ids": [1,2]
    }
    ```
- **Error Responses:**
  - `