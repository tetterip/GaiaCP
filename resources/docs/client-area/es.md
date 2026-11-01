## Iniciar sesión

Inicie sesión con su dirección de email y su contraseña. Si olvida la contraseña, pulse "¿Ha olvidado su contraseña?" en la página de inicio de sesión y le enviaremos por email un enlace para elegir una nueva. El enlace funciona una vez.

<!-- if:google_login -->
Si su dirección de email es una cuenta de Google, también puede pulsar **Continuar con Google** en la página de inicio de sesión en lugar de escribir su contraseña.
<!-- endif -->

<!-- if:email_link -->
En lugar de la contraseña, puede pulsar **Enviarme un enlace de acceso**: le enviamos por email un enlace que funciona una vez, durante 15 minutos. Ábralo y pulse **Iniciar sesión** en la página que se abre.
<!-- endif -->

<!-- if:passkeys -->
Las **passkeys** le permiten iniciar sesión con su huella, su rostro o el bloqueo de pantalla en lugar de su contraseña. Añada una en cada teléfono u ordenador desde su perfil con **Añadir una passkey en este dispositivo** y luego pulse **Iniciar sesión con una passkey** en la página de inicio de sesión. La página de inicio de sesión marca con «Último uso» la forma en que entró por última vez desde este dispositivo.
<!-- endif -->

Su perfil (el menú con su nombre, al final del menú o arriba a la derecha) cambia su dirección de email, teléfono, idioma y contraseña, y puede activar la verificación en dos pasos para mayor seguridad.

<!-- if:notifications -->
La **campana** de la parte superior de la página le indica lo que ha pasado desde la última vez que miró: un aviso de pago nuevo, un recordatorio de pago o aviso de retraso, un pago que recibimos, un servicio listo, suspendido o terminado, un dominio a punto de renovarse, un precio nuevo, nuestra respuesta a su ticket o a una solicitud. Un número sobre ella muestra cuántas no ha leído; pulse una para abrir su página. En un móvil o tableta, deslice una a la izquierda o a la derecha para quitarla. Para dejar de recibirlas, desactive **Notificaciones** en su perfil. Le seguiremos enviando emails como antes.

<!-- endif -->
<!-- if:email_history -->
**Historial de emails**, en el mismo menú, lista los emails que le enviamos, los más recientes primero: pulse uno para leerlo de nuevo. Los emails con una contraseña o un enlace de acceso se listan sin su texto; use en su lugar "¿Ha olvidado su contraseña?" en la página de inicio de sesión.

<!-- endif -->
**Dispositivos e inicios de sesión**, en el mismo menú, lista los navegadores con sesión iniciada en su cuenta y sus inicios de sesión recientes, incluidas las contraseñas incorrectas. Pulse **Cerrar sesión** junto a un dispositivo que ya no use, o **Cerrar sesión en otros dispositivos** para cerrarla en todas partes menos aquí. Si ve un inicio de sesión que no reconoce, cambie también su contraseña.

<!-- if:cookies -->
"Ajustes de cookies", al pie de cada página, vuelve a mostrar la elección de cookies, para que pueda cambiarla.
<!-- endif -->

<!-- if:welcome_tour -->
La primera vez que inicia sesión, un breve recorrido muestra lo que puede hacer aquí. El menú con su nombre → **Recorrido** lo muestra de nuevo.
<!-- endif -->

## Inicio

Inicio muestra lo que tiene que pagar, cuántos servicios están activos y qué se renueva a continuación, con un enlace a cada cosa. Nuestros anuncios, como trabajos programados en su servidor, aparecen en la parte superior de Inicio mientras estén vigentes.

## Servicios y dominios

**Servicios** lista su hosting y sus otros servicios con su estado, precio y próximo vencimiento. El menú los divide en **Hosting** y **Otros servicios**. La página de una cuenta de hosting muestra su ciclo de facturación y tiene un botón **Abrir sitio web**. **Dominios** lista sus dominios con su fecha de vencimiento, sus servidores de nombres y si se renuevan automáticamente. Cuando tiene servicios en más de un estado, los botones sobre cada lista muestran solo los de un estado (Activo, Suspendido, …), con cuántos hay. Un trabajo hecho una vez, como trasladar su sitio web, se cobra una sola vez y aparece como **Completado** después de pagarlo.

<!-- if:hosting -->
En una cuenta de hosting activa puede:

<!-- if:hosting_login -->
- **Entrar en el panel de control** (cPanel o Plesk) sin escribir su contraseña: el botón lo abre en una pestaña nueva.
- Usar los **Atajos rápidos** (en cPanel y Plesk) para abrir directamente una página del panel de control, como las cuentas de email, el administrador de archivos o las bases de datos, ya con la sesión iniciada.
<!-- endif -->
<!-- if:hosting_usage -->
- **Ver cuánto espacio en disco y ancho de banda usa** este mes, frente a lo que permite su paquete.
<!-- endif -->
<!-- if:hosting_uptime -->
- **Ver la disponibilidad del servidor**: qué parte de los últimos 30 días y 24 horas estuvo activo el servidor en el que está su cuenta. En la lista de Hosting, un punto delante del dominio muestra el estado actual del servidor: verde mientras funciona, rojo mientras no responde, naranja durante el mantenimiento. Pase el puntero sobre él para ver la disponibilidad de los últimos 30 días.
<!-- endif -->
<!-- if:vps_power -->
- **Iniciar, apagar o reiniciar un VPS** con los botones de su página. Apagar y Reiniciar detienen los programas que se ejecutan en él, así que guarde antes su trabajo. Para usar su consola, pulse **Entrar en Proxmox VE** e inicie sesión con el nombre de usuario y la contraseña del servicio (realm "Proxmox VE authentication server"): solo verá su propio VPS.
<!-- endif -->
<!-- if:hosting_password -->
- **Cambiar la contraseña** de la cuenta. Use al menos 10 caracteres con mayúsculas, minúsculas y números.
<!-- endif -->
<!-- endif -->

Para pasar un servicio a un paquete mayor o menor, pulse **Mejorar / reducir** en su página y elija el nuevo; cada uno muestra su precio para su ciclo de facturación. Cuando lo aprobemos, el paquete nuevo se aplica enseguida y el precio nuevo desde su próximo vencimiento (puede que también le enviemos un aviso de pago por el resto del periodo actual). Se lo confirmaremos por email; hasta entonces la página muestra la solicitud y **Retirar solicitud de cambio de paquete** la anula.

<!-- if:orders -->
Cuando hay complementos disponibles para un servicio (como copias de seguridad diarias), pulse **Añadir un complemento** en su página y elija uno; cada uno muestra su precio para su ciclo de facturación y lo que paga ahora. Ahora solo paga los días hasta el próximo vencimiento del servicio (más la cuota de alta, si la hay), y a partir de entonces el complemento se renueva junto con el servicio, en el mismo aviso de pago. El aviso de pago se abre enseguida; el complemento se activa cuando está pagado, y la página lo lista en **Complementos** (con un enlace al aviso mientras espera).

<!-- endif -->
Para dar de baja una cuenta de hosting u otro servicio, pulse **Solicitar cancelación** en su página. Indique el motivo si quiere, y elija si termina **al final del periodo de facturación** (lo sigue usando hasta su próximo vencimiento) o **inmediatamente** (se borran la cuenta y sus archivos). Si tiene un dominio con el mismo nombre, también puede pedirnos que no lo renovemos. Se lo confirmaremos por email cuando la hayamos realizado; hasta entonces la página muestra la solicitud y **Retirar solicitud de cancelación** la anula. Para un dominio, desactive en su lugar la renovación automática.

Cuando cambiamos el precio de un servicio, se lo comunicamos por email con antelación, y su página muestra el precio nuevo en **Precio** con la fecha desde la que se aplica (por ejemplo *Desde el 01/12/2026: €66.00*). Las renovaciones que vencen antes de esa fecha mantienen el precio actual, y los avisos de pago ya emitidos no cambian.

<!-- if:incident_notice -->
Si un servidor en el que está su hosting tiene un problema o está en mantenimiento, un aviso en la parte superior de cada página lo indica y nombra los servicios afectados. Desaparece solo cuando todo vuelve a funcionar, así que no hace falta que nos contacte por ello.

<!-- endif -->
<!-- if:ssl -->
Un **certificado SSL** se pide para el dominio que indique al pedirlo, una vez pagado. Su página muestra qué hacer para demostrar que el dominio es suyo (la autoridad de certificación lo comprueba antes de emitir nada), y también se lo enviamos por email:

- **Registro DNS (CNAME):** añada el registro mostrado (nombre, tipo y valor) a la zona DNS del dominio.
- **Archivo en el sitio web:** cree el archivo mostrado, con exactamente su contenido, para que se abra en la dirección mostrada.
- **Email al administrador del dominio:** abra el enlace del email enviado a admin@ (o a la dirección elegida) y apruebe.

Cuando el dominio es una cuenta de hosting que tiene con nosotros, lo hacemos por usted: su página indica que ya está hecho y, una vez emitido el certificado, también lo instalamos en esa cuenta, así que no tiene que descargar ni instalar nada.

**Cambiar validación** elige otra forma (u otra dirección de aprobación) y **Comprobar de nuevo** pide a la autoridad de certificación que lo revise ahora (**Enviar de nuevo el email de aprobación** cuando es por email). Cuando se emite el certificado se lo comunicamos por email, y **Descargar certificado** le da un zip con el certificado, el CA bundle y, cuando la creamos nosotros, la clave privada, listo para instalar en el servidor. Un certificado dura como máximo 200 días, así que mientras su servicio esté en marcha emitimos el siguiente antes de que termine cada uno: usted valida el dominio de nuevo cada vez y descarga el certificado nuevo.

<!-- endif -->
<!-- if:domains -->
En un dominio activo registrado a través de nosotros puede:

<!-- if:domain_nameservers -->
- **Cambiar los servidores de nombres** (de 2 a 4). El cambio llega al registro enseguida; internet lo ve en unas horas.
<!-- endif -->
<!-- if:domain_epp -->
- **Obtener el código EPP** que necesita para trasladar el dominio a otro proveedor. Para algunos dominios se muestra en pantalla; para otros se envía por email a la dirección de email del titular del dominio.
<!-- endif -->
<!-- if:domain_auto_renew -->
- **Desactivar o activar la renovación automática.** Si está desactivada, no recibirá más avisos de pago por el dominio y este termina en su fecha de vencimiento.
<!-- endif -->
<!-- if:domain_contacts -->
- **Cambiar los datos de contacto** (titular, administrativo, técnico y, en algunos dominios, de facturación) tal como los tiene el registro. **Usar los datos de mi cuenta** rellena un contacto a partir de su perfil e **Igual que el titular** copia los del titular. Algunos registros (como .gr) no permiten cambiar así el nombre del titular: contáctenos para ello.
<!-- endif -->
<!-- if:domain_child_nameservers -->
- **Registrar servidores de nombres privados** con el nombre de su dominio (como ns1.sudominio.gr) con su dirección IP, cambiar su IP o borrarlos.
<!-- endif -->
<!-- if:domain_lock -->
- **Bloquear o desbloquear el dominio** (bloqueo del registrador). Un dominio bloqueado no se puede trasladar a otro proveedor; desbloquéelo solo justo antes de una transferencia. No todos los registros tienen bloqueo.
<!-- endif -->
<!-- endif -->

## Avisos de pago y pagos

Cuando vence un servicio le enviamos un **aviso de pago** por email, con cómo pagar. Es un aviso de pago, no una factura fiscal. **Avisos de pago** los lista todos: abra uno para ver sus líneas, imprimirlo o guardarlo como PDF. Los botones sobre la lista muestran solo los sin pagar, los pagados o los vencidos. **Pagos** lista los pagos que hemos registrado. Después de pagar, el aviso aparece como pagado en cuanto registramos su pago.

<!-- if:invoices -->
Cuando emitimos facturas, un aviso de pago pagado también recibe una **factura**: se la enviamos por email en PDF, el aviso pagado tiene un botón para descargarla y **Facturas** (en Facturación) las muestra todas. Una **nota de crédito** allí, con un total negativo, anula una factura, por ejemplo cuando se devuelve un pago.
<!-- endif -->

<!-- if:pay_card -->
Para pagar con tarjeta, abra un aviso de pago sin pagar y pulse **Pagar con tarjeta**. Paga en la página de pago segura de Stripe (nunca vemos los datos de su tarjeta), y el aviso aparece como pagado en cuanto se realiza el pago.
<!-- endif -->

<!-- if:pay_paypal -->
Para pagar con PayPal, abra un aviso de pago sin pagar y pulse **Pagar con PayPal**. Aprueba el pago en la página de PayPal, con su saldo de PayPal o con una tarjeta, y el aviso aparece como pagado en cuanto PayPal lo confirma.
<!-- endif -->

<!-- if:credit -->
## Presupuestos

Cuando le enviamos un presupuesto, recibe un email con un enlace a él, y **Presupuestos** (en Facturación) lista los que le hemos enviado, con su primer pago y hasta cuándo son válidos. Pulse uno para abrirlo: allí puede imprimirlo. **Aceptar** realiza el pedido a los precios del presupuesto (con nuestras Condiciones del Servicio, cuando le pedimos aceptarlas), y se le envía por email su aviso de pago con cómo pagar. **Rechazar** nos indica que no lo quiere; decirnos el motivo nos ayuda. Una vez pasada su fecha, un presupuesto no se puede aceptar: responda a nuestro email y le enviaremos uno nuevo.

## Saldo

**Saldo** muestra su saldo disponible y su historial. Para añadir fondos, pulse **Añadir fondos** e introduzca un importe: creamos un aviso de pago por él, que paga como cualquier otro. Una vez pagado, el importe se añade a su saldo. Puede añadir fondos cuando tenga un servicio activo.

El saldo paga sus avisos de pago línea a línea: una línea (la renovación de un servicio) se paga solo cuando su saldo la cubre entera, y lo que no cubre se queda en el aviso para pagarlo como siempre.
<!-- if:credit_auto -->
Usamos su saldo automáticamente en los avisos de pago nuevos, y en los pendientes cuando añade fondos.
<!-- endif -->
<!-- if:credit_manual -->
Para usarlo, abra un aviso de pago sin pagar y pulse **Pagar con saldo**.
<!-- endif -->
<!-- endif -->

<!-- if:orders -->
## Hacer pedidos

**Búsqueda de dominios** (en el menú, después de Dominios) le encuentra un nombre: escríbalo (mitienda, o mitienda.gr) y lo verá con cada terminación que registramos, si está libre u ocupado, y su precio. **Añadir al carrito** pone uno libre en el carrito (**Con SSL** añade también un certificado para él, cuando lo ofrecemos); para uno que es suyo pero está en otro sitio, **Es mío: transferirlo** abre el pedido de la transferencia. Puede que primero se le pida confirmar que no es un robot. “Probablemente disponible” significa que no se pudo consultar al registro en ese momento; lo comprobamos de nuevo al registrarlo. Cuando lo ofrecemos, **Sugerir otros nombres** bajo los resultados lista nombres libres parecidos al suyo, sugeridos por IA, con la misma terminación.

<!-- if:domain_prices -->
**Ver los precios de cada terminación de dominio**, bajo el cuadro de búsqueda, lista cada terminación que vendemos con su precio de registro, transferencia y renovación.
<!-- endif -->

**Pedido** muestra lo que puede pedir; **Pedir un servicio nuevo**, sobre sus Servicios, también lo abre (**Pedir un dominio**, sobre sus Dominios, abre la búsqueda de dominios). Cuando vendemos varios tipos de servicio, elija primero el tipo (como hosting compartido o un VPS, o nombres de dominio) y luego un paquete: **Comparar paquetes**, debajo, muestra lo que incluye cada uno lado a lado, con su precio para cada periodo de facturación, y sus botones **Elegir** también eligen un paquete. Las insignias junto al nombre de un paquete (como Popular o Nuevo) señalan lo que recomendamos o lo que es nuevo. Elija un servicio y su periodo de facturación, y las opciones que ofrezca (como una ubicación o direcciones IP adicionales); cada opción muestra lo que añade al precio. Los complementos para él (como copias de seguridad diarias) se pueden marcar debajo; van en el mismo aviso de pago y se renuevan junto con él. Para un hosting, indique si ya tiene el dominio, si quiere registrar uno nuevo o trasladar uno que tiene en otro sitio (necesita su código EPP). También puede pedir un dominio solo, con un certificado SSL para él cuando lo ofrecemos (márquelo bajo el dominio). Cuando escribe un dominio para registrar o transferir, ve a su lado si está libre; un dominio ocupado solo se puede transferir, y uno aún no registrado solo se puede registrar.

Pulse **Añadir al carrito** y se abre el **Carrito** (el icono del carrito en la parte superior de cada página también lo abre, con cuántos elementos tiene). Pulse **Pedir otra cosa** para añadir más, como dos dominios y tres planes de hosting, o la papelera junto a un elemento para quitarlo. Todo lo del carrito se convierte en un solo pedido con un solo aviso de pago.

Si tiene un **código de cupón**, escríbalo en el carrito: verá lo que descuenta antes de realizar el pedido (un enlace con un código lo rellena por usted). Un cupón puede rebajar solo el primer pago o también cada renovación, y nunca rebaja una cuota de alta.

Lo que está **en oferta** muestra la insignia Oferta y su precio normal tachado. El precio de oferta es para el primer periodo de lo que pida mientras dure la oferta; las renovaciones son al precio normal. Es posible que un cupón no rebaje más un precio de oferta.

<!-- if:terms -->
Antes de hacer el pedido, lea nuestras Condiciones del Servicio (el enlace junto a la casilla) y marque **Acepto las Condiciones del Servicio**.
<!-- endif -->

Cuando hace el pedido, le enviamos por email su aviso de pago. Configuramos los servicios en cuanto el pedido está listo, y entonces comprobamos y registramos el dominio.
<!-- endif -->

## Sus datos

Su nombre, empresa, datos de IVA y dirección aparecen en sus avisos de pago y como titular de sus dominios. Puede pedir cambiarlos desde su perfil; revisamos el cambio y lo aprobamos. El email y el teléfono cambian al momento, igual que los datos adicionales que podamos pedirle en Más datos.

Nuestras noticias y ofertas terminan con un enlace para dejar de recibirlas; los emails sobre sus servicios, avisos de pago y recordatorios siguen llegando.

<!-- if:whois -->
## WHOIS

**WHOIS** en el menú consulta un nombre de dominio: si está registrado, con qué registrador, cuándo se registró y cuándo vence, sus estados y sus servidores de nombres. No se muestran los datos de contacto del titular. Si el nombre está libre, **Registrarlo** le lleva a la búsqueda de dominios.
<!-- endif -->

<!-- if:knowledge_base -->
## Base de conocimiento

En Soporte → Base de conocimiento encontrará respuestas a preguntas frecuentes, agrupadas por tema. Escriba unas palabras en la búsqueda para encontrar un artículo; los encuentra con o sin acentos. Al final de cada artículo, díganos si le ayudó.
<!-- if:tickets -->
Mientras escribe el asunto de un ticket nuevo, debajo aparecen artículos que quizá ya lo respondan.
<!-- endif -->

<!-- endif -->
<!-- if:downloads -->
## Descargas

En Soporte → **Descargas** encontrará archivos que compartimos con usted, como guías y software, agrupados por tema. Algunos solo están mientras tenga un servicio concreto. Pulse **Descargar** para guardar uno.
<!-- endif -->
<!-- if:tickets -->
## Tickets de soporte

En Soporte → Tickets, **Abrir un ticket** para preguntarnos lo que sea: elija el departamento y el servicio al que se refiere, escriba su pregunta y adjunte archivos como capturas de pantalla. Respondemos en el ticket y por email; pulse **Responder** en él para contestar. Ciérrelo cuando no necesite nada más. Un ticket cerrado se vuelve a abrir si responde, y uno que respondimos se cierra solo cuando no sabemos nada de usted en unos días.
<!-- endif -->
<!-- if:ticket_mail -->
También puede responder a nuestros emails de tickets desde su propio buzón: su respuesta se añade al ticket, con sus archivos adjuntos. Un email que envíe a nuestra dirección de soporte abre un ticket nuevo.
<!-- endif -->

<!-- if:announcements -->
## Anuncios

En Soporte → Anuncios publicamos noticias sobre nuestros servicios: servicios nuevos, cambios de precio y mantenimiento programado, los más recientes primero.
<!-- endif -->
<!-- if:server_status -->
## Estado de los servidores

Soporte → Estado de los servidores muestra si nuestros servidores funcionan ahora mismo, con los cuadros de los últimos 30 días y 24 horas. Cuando uno tiene un problema, aparece arriba con lo que sabemos de él.
<!-- endif -->
<!-- if:contact_page -->
## Contáctenos

Soporte → Contáctenos tiene nuestra dirección, teléfono, email y horario de atención, tal como los completamos. Pulse un número de teléfono o una dirección de email para llamar o escribir, y **Abrir en mapas** para encontrarnos.
<!-- endif -->

## Preguntas

Escríbanos a {company_email}.
