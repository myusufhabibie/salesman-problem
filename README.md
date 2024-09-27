# salesman-problem
Solving Salesman Problem by combining Clustering and Nearest Neighbour

# Requirements
Using Docker:
-Docker Desktop

Without Docker:
- PHP 8.2+
- Composer
  
# Installation (Using Docker)
1. Clone the repository
2. Using terminal, goes to the cloned repository
3. In terminal, run this command:
   ```
    docker-compose up
   ```
4. After Docker Container created, run this command
   ```
   docker-compose exec app composer install
   ```
5. After composer finish installing the dependencies, run this command:
   ```
   docker-compose exec app cp .env.example .env
   ```
6. After composer finish installing the dependencies, run this command:
   ```
   docker-compose exec app php artisan key:generate
   ```
7. Run the application in your browser at http://localhost:8081

# Installation (Without Docker)
1. Clone the repository
2. Using terminal, goes to the cloned repository
3. In terminal, run this command:
   ```
   composer install
   ```
4. After composer finish installing the dependencies, run this command:
   ```
   php artisan key:generate
   ```
5. After composer finish installing the dependencies, run this command:
   ```
   php artisan serve --port=8081
   ```
6. Run the application in your browser at http://localhost:8081
