# Fursuit Registration Tool

This tool is using Laravel 11 with inertia.js (Vue).

## Contributing

#### Prerequisites
- PHP `>=8.3`
- Composer
- Docker (+ Compose plugin)

### Windows

- tbd.

### OSX / Linux Setup
1. Rename `.env.example` to `.env`
    ```sh
    mv .env.example .env
    ```

2. Adjust env variables if necessary

3. Install PHP dependencies
    ```sh
    composer install
    ```
4. Install Node dependencies
    ```sh
    npm install
    ```
4. Install Node dependencies
    ```sh
    npm run build
    ```
5. Start sailing
    ```sh
    ./vendor/bin/sail up
    ```
6. Migrate Database
    ```sh
    ./vendor/bin/sail artisan migrate
    ```
7. Open `http://localhost:80` in your browser

## Troubleshooting

### REPL
You can open a repl session with Tinker, calling:
```sh
./vendor/bin/sail artisan tinker
```

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
