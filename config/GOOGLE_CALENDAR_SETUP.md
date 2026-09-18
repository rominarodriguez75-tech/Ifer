# Configuracion de Google Calendar

1. En Google Cloud Console, habilitar la API **Google Calendar API** para el proyecto de IFER.
2. Crear una cuenta de servicio y descargar su archivo JSON.
3. Crear o elegir un calendario de trabajo en Google Calendar.
4. Compartir ese calendario con el email `client_email` del JSON y darle permiso **Hacer cambios en eventos**.
5. Configurar en el servidor:

```text
IFER_GOOGLE_CALENDAR_ID=el-id-del-calendario
IFER_GOOGLE_SERVICE_ACCOUNT_JSON=/ruta/segura/ifer-service-account.json
```

El ID se obtiene en Google Calendar, en **Configuracion y uso compartido > Integrar calendario > ID de calendario**.

No subir el archivo JSON al repositorio. El proyecto excluye `config/*.json` mediante `.gitignore`.

Para probar localmente en macOS:

```bash
IFER_GOOGLE_CALENDAR_ID='el-id-del-calendario' \
IFER_GOOGLE_SERVICE_ACCOUNT_JSON='/ruta/segura/ifer-service-account.json' \
php -S 127.0.0.1:8080 -t .
```
