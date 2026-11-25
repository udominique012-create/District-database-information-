# Umurenge wa Mushishiro

- `backend/`  PHP 8 + MySQL (API, SMS, uploads, database)
- `frontend/` HTML + CSS + JavaScript (ntacyo ikeneye uretse seriveri ya static)

## Iterambere (terminal ebyiri)
```
# 1) backend
cd backend && php database/install.php
CORS_ORIGIN=http://localhost:3000 php -S localhost:8000 -t public public/router.php
# 2) frontend
cd frontend && php -S localhost:3000
```
Mu `frontend/assets/config.js` shyiramo `{ API:'http://localhost:8000', FILES:'http://localhost:8000' }`.

## Seriveri nyayo (domain imwe)
- Shyira ibiri muri `frontend/` mu mizi y'urubuga (public_html).
- Shyira `backend/public/` muri `public_html/api/`, naho `backend/src` na `backend/database` hanze ya public_html.
  Mu `api.php` umurongo `require __DIR__.'/../src/core.php'` uhindurwe ukurikije aho `src` iri.
- Hindura `backend/src/config.php`, hanyuma ukore `php database/install.php`. Koresha HTTPS.
