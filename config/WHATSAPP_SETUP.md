# Configuracion de WhatsApp con Twilio

Para probar el envio:

1. Crear una cuenta en Twilio.
2. Activar **WhatsApp Sandbox** desde Messaging > Try it out > Send a WhatsApp message.
3. Hacer que el telefono de prueba se una al Sandbox siguiendo el codigo que muestra Twilio.
4. Copiar el **Account SID**, el **Auth Token** y el numero/remitente de WhatsApp del Sandbox.
5. Iniciar el servidor con estas variables:

```bash
IFER_APP_BASE_URL='https://tu-dominio.com' \
IFER_TWILIO_SID='ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' \
IFER_TWILIO_TOKEN='token-secreto' \
IFER_TWILIO_FROM='whatsapp:+14155238886' \
php -S 127.0.0.1:8080 -t .
```

El telefono del paciente debe incluir codigo de pais, por ejemplo `+54911XXXXXXXX`.

En produccion, usar variables de entorno del hosting. No guardar el Auth Token en GitHub.

El Sandbox sirve para pruebas. Para produccion se necesita un remitente WhatsApp aprobado por Twilio y, para mensajes iniciados fuera de la ventana de 24 horas, plantillas aprobadas por WhatsApp.
