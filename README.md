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

## Excel Import Format

The system supports two Excel formats:

### Standard Format
Headers: `client_name`, `client_email`, `client_address`, `client_phone`, `invoice_total`, `invoice_status`, `item_description`, `item_quantity`, `item_price`, `item_total`

### Custom Report Format
Automatically detects columns with Albanian headers:
- `pershkrimi` (description)
- `njesia` (unit)
- `sasia` (quantity)
- `cmimi` (price)

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
