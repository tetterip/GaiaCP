## Accesso

Acceda con il Suo indirizzo email e la Sua password. Se dimentica la password, prema "Password dimenticata?" nella pagina di accesso e Le invieremo via email un link per sceglierne una nuova. Il link funziona una sola volta.

<!-- if:google_login -->
Se il Suo indirizzo email è un account Google, può anche premere **Continua con Google** nella pagina di accesso invece di digitare la password.
<!-- endif -->

Il Suo profilo (il menu con il Suo nome, in fondo al menu o in alto a destra) permette di cambiare indirizzo email, numero di telefono, lingua e password, e di attivare l'autenticazione a due fattori per una maggiore sicurezza.

<!-- if:notifications -->
La **campanella** in cima alla pagina Le indica cosa è successo dall'ultima volta che ha guardato: un nuovo avviso di pagamento, un promemoria di pagamento o un avviso di ritardo, un pagamento che abbiamo ricevuto, un servizio pronto, sospeso o terminato, un dominio in rinnovo, un nuovo prezzo, la nostra risposta al Suo ticket o a una richiesta. Un numero indica quante non ha ancora letto; ne prema una per aprirne la pagina. Per non riceverle più, disattivi **Notifiche** nel Suo profilo. Continueremo comunque a inviarLe le email come prima.

<!-- endif -->
<!-- if:email_history -->
**Cronologia email**, nello stesso menu, elenca le email che Le abbiamo inviato, dalla più recente: ne prema una per rileggerla. Le email con una password o un link di accesso sono elencate senza testo; usi invece "Password dimenticata?" nella pagina di accesso.

<!-- endif -->
**Dispositivi e accessi**, nello stesso menu, elenca i browser con accesso al Suo account e i Suoi accessi recenti, password errate incluse. Prema **Esci** accanto a un dispositivo che non usa più, o **Disconnetti gli altri dispositivi** per uscire ovunque tranne che qui. Se vede un accesso che non riconosce, cambi anche la password.

<!-- if:cookies -->
"Impostazioni cookie" in fondo a ogni pagina mostra di nuovo la scelta sui cookie, così può cambiarla.
<!-- endif -->

<!-- if:welcome_tour -->
Al primo accesso, un breve tour mostra cosa può fare qui. Il menu con il suo nome → **Tour** lo mostra di nuovo.
<!-- endif -->

## Home

La Home mostra quanto deve pagare, quanti servizi sono attivi e cosa si rinnova per primo, con un link a ciascuno. I nostri annunci, come lavori programmati sul Suo server, appaiono in cima alla Home finché sono validi.

## Servizi e domini

**Servizi** elenca il Suo hosting e gli altri servizi con stato, prezzo e prossima scadenza. Il menu li divide in **Hosting** e **Altri servizi**. La pagina di un account hosting mostra il suo ciclo di fatturazione e ha un pulsante **Apri sito web**. **Domini** elenca i Suoi domini con data di scadenza, nameserver e se si rinnovano automaticamente. Quando ha servizi in più di uno stato, i pulsanti sopra ogni elenco mostrano solo quelli in uno stato (Attivo, Sospeso, …), con il loro numero. Un lavoro svolto una volta, come lo spostamento del Suo sito web, viene addebitato una volta e appare come **Completato** dopo il pagamento.

<!-- if:hosting -->
Su un account hosting attivo può:

<!-- if:hosting_login -->
- **Accedere al pannello di controllo** (cPanel o Plesk) senza digitarne la password: il pulsante lo apre in una nuova scheda.
- Usare le **Scorciatoie rapide** (su cPanel e Plesk) per aprire direttamente una pagina del pannello di controllo, come account email, file manager o database, con l'accesso già effettuato.
<!-- endif -->
<!-- if:hosting_usage -->
- **Vedere quanto spazio su disco e banda usa** questo mese, rispetto a quanto consente il Suo pacchetto.
<!-- endif -->
<!-- if:hosting_uptime -->
- **Vedere l'uptime del server**: per quanta parte degli ultimi 30 giorni e delle ultime 24 ore il server su cui si trova il Suo account è stato online. Nell'elenco Hosting, un punto prima del dominio mostra lo stato attuale del server: verde quando funziona, rosso quando non risponde, arancione durante la manutenzione. Passi sopra il punto per vedere l'uptime degli ultimi 30 giorni.
<!-- endif -->
<!-- if:vps_power -->
- **Avviare, spegnere o riavviare un VPS** con i pulsanti nella sua pagina. Spegni e Riavvia fermano i programmi in esecuzione, quindi salvi prima il Suo lavoro. Per usarne la console, prema **Accedi a Proxmox VE** ed entri con il nome utente e la password del servizio (realm "Proxmox VE authentication server"): vedrà solo il Suo VPS.
<!-- endif -->
<!-- if:hosting_password -->
- **Cambiare la password** dell'account. Usi almeno 10 caratteri con lettere maiuscole, minuscole e numeri.
<!-- endif -->
<!-- endif -->

Per passare a un pacchetto più grande o più piccolo, prema **Upgrade / downgrade** nella pagina del servizio e scelga quello nuovo; ognuno mostra il suo prezzo per il Suo ciclo di fatturazione. Una volta approvato, il nuovo pacchetto si applica subito e il nuovo prezzo dalla Sua prossima scadenza (potremmo anche inviarLe un avviso di pagamento per la parte restante del periodo in corso). Confermiamo via email; fino ad allora la pagina mostra la richiesta e **Ritira la richiesta di cambio pacchetto** la annulla.

<!-- if:orders -->
Quando per un servizio sono disponibili degli add-on (come i backup giornalieri), prema **Aggiungi un add-on** nella sua pagina e ne scelga uno; ognuno mostra il suo prezzo per il Suo ciclo di fatturazione e quanto paga ora. Ora paga solo i giorni fino alla prossima scadenza del servizio (più l'eventuale costo di attivazione), e da allora l'add-on si rinnova insieme al servizio, sullo stesso avviso di pagamento. L'avviso di pagamento si apre subito; l'add-on si attiva una volta pagato, e la pagina lo elenca sotto **Add-on** (con un link all'avviso finché è in attesa).

<!-- endif -->
Per interrompere un account hosting o un altro servizio, prema **Richiedi la disdetta** nella sua pagina. Ne indichi il motivo se vuole, e scelga se termina **alla fine del periodo di fatturazione** (continua a usarlo fino alla prossima scadenza) o **immediatamente** (l'account e i suoi file vengono eliminati). Se ha un dominio con lo stesso nome, può anche chiederci di non rinnovarlo. Confermiamo via email una volta eseguita; fino ad allora la pagina mostra la richiesta e **Ritira la richiesta di disdetta** la annulla. Per un dominio, disattivi invece il rinnovo automatico.

Quando cambiamo il prezzo di un servizio, glielo comunichiamo via email in anticipo, e la sua pagina mostra il nuovo prezzo sotto **Prezzo** con la data da cui si applica (ad esempio *Dal 01/12/2026: €66.00*). I rinnovi in scadenza prima di quella data mantengono il prezzo attuale, e gli avvisi di pagamento già emessi non cambiano.

<!-- if:incident_notice -->
Se un server su cui si trova il Suo hosting ha un problema o è in manutenzione, un avviso in cima a ogni pagina lo segnala e indica i servizi coinvolti. Scompare da solo quando tutto torna a funzionare, quindi non è necessario contattarci.

<!-- endif -->
<!-- if:ssl -->
Un **certificato SSL** viene ordinato per il dominio indicato al momento dell'ordine, una volta pagato. La sua pagina mostra cosa fare per dimostrare che il dominio è Suo (l'autorità di certificazione lo verifica prima di emettere qualsiasi cosa), e glielo inviamo anche via email:

- **Record DNS (CNAME):** aggiunga il record mostrato (nome, tipo e valore) alla zona DNS del dominio.
- **File sul sito web:** crei il file mostrato, con esattamente il suo contenuto, in modo che si apra all'indirizzo mostrato.
- **Email all'amministratore del dominio:** apra il link nell'email inviata ad admin@ (o all'indirizzo scelto) e approvi.

Quando il dominio è un account di hosting che ha presso di noi, lo facciamo noi per Lei: la sua pagina indica che è già a posto e, una volta emesso il certificato, lo installiamo anche su quell'account, quindi non deve scaricare né installare nulla.

**Cambia convalida** sceglie un altro metodo (o un altro indirizzo di approvazione) e **Verifica di nuovo** chiede all'autorità di certificazione di controllare subito (**Invia di nuovo l'email di approvazione** quando è via email). Una volta emesso il certificato glielo comunichiamo via email, e **Scarica certificato** fornisce uno zip con il certificato, il CA bundle e, quando l'abbiamo creata noi, la chiave privata, pronti da installare sul server. Un certificato dura al massimo 200 giorni, quindi finché il Suo servizio è attivo emettiamo il successivo prima della scadenza di ciascuno: ogni volta convalida di nuovo il dominio e scarica il nuovo certificato.

<!-- endif -->
<!-- if:domains -->
Su un dominio attivo registrato tramite noi può:

<!-- if:domain_nameservers -->
- **Modificare i nameserver** (da 2 a 4). La modifica arriva subito al registro; internet la vede entro poche ore.
<!-- endif -->
<!-- if:domain_epp -->
- **Ottenere il codice EPP** necessario per trasferire il dominio a un altro provider. Per alcuni domini viene mostrato a schermo; per altri viene inviato all'indirizzo email del titolare del dominio.
<!-- endif -->
<!-- if:domain_auto_renew -->
- **Disattivare o attivare il rinnovo automatico.** Se disattivato, non riceverà più avvisi di pagamento per il dominio, che terminerà alla scadenza.
<!-- endif -->
<!-- if:domain_contacts -->
- **Modificare i dati di contatto** (titolare, amministrativo, tecnico e, per alcuni domini, contatto di fatturazione) come li ha il registro. **Usa i dati del mio account** compila un contatto dal Suo profilo e **Uguale al titolare** copia quelli del titolare. Alcuni registri (come .gr) non consentono di cambiare così il nome del titolare: per questo ci contatti.
<!-- endif -->
<!-- if:domain_child_nameservers -->
- **Registrare nameserver privati** con il nome del Suo dominio (come ns1.ilsuodominio.it) con il loro indirizzo IP, cambiarne l'IP o eliminarli.
<!-- endif -->
<!-- if:domain_lock -->
- **Bloccare o sbloccare il dominio** (blocco del registrar). Un dominio bloccato non può essere trasferito a un altro provider; lo sblocchi solo poco prima di un trasferimento. Non tutti i registri hanno un blocco.
<!-- endif -->
<!-- endif -->

## Avvisi di pagamento e pagamenti

Quando un servizio è in scadenza Le inviamo via email un **avviso di pagamento**, con le istruzioni per pagare. È un avviso di pagamento, non una fattura fiscale. **Avvisi di pagamento** li elenca tutti: ne apra uno per vederne le righe, stamparlo o salvarlo come PDF. I pulsanti sopra l'elenco mostrano solo quelli non pagati, pagati o scaduti. **Pagamenti** elenca i pagamenti che abbiamo registrato. Dopo che ha pagato, l'avviso risulta pagato una volta registrato il Suo pagamento.

<!-- if:invoices -->
Quando emettiamo fatture, un avviso di pagamento pagato riceve anche una **fattura**: te la inviamo via email in PDF, l'avviso pagato ha un pulsante per scaricarla e **Fatture** (in Fatturazione) le elenca tutte. Una **nota di credito** lì, con un totale negativo, annulla una fattura, ad esempio quando un pagamento viene restituito.
<!-- endif -->

<!-- if:pay_online -->
Per pagare con carta, apra un avviso di pagamento non pagato e prema **Paga con carta**. Paga sulla pagina di pagamento sicura di Stripe (non vediamo mai i dati della Sua carta), e l'avviso risulta pagato appena il pagamento va a buon fine.
<!-- endif -->

<!-- if:credit -->
## Preventivi

Quando Le inviamo un preventivo, riceve un'email con un link, e **Preventivi** (in Fatturazione) elenca quelli che Le abbiamo inviato, con il primo pagamento e la durata di validità. Ne prema uno per aprirlo: lì può stamparlo. **Accetta** effettua l'ordine ai prezzi del preventivo (con le nostre Condizioni di servizio, quando Le chiediamo di accettarle), e il relativo avviso di pagamento Le viene inviato via email con le istruzioni per pagare. **Rifiuta** ci comunica che non le interessa; indicarne il motivo ci aiuta. Passata la sua data, un preventivo non può essere accettato: risponda alla nostra email e Le invieremo uno nuovo.

## Credito

**Credito** mostra il saldo del Suo credito e la sua cronologia. Per aggiungere fondi, prema **Aggiungi fondi** e inserisca un importo: creiamo un avviso di pagamento, che paga come qualsiasi altro. Una volta pagato, l'importo viene aggiunto al Suo credito. Può aggiungere fondi quando ha un servizio attivo.

Il credito paga i Suoi avvisi di pagamento una riga alla volta: una riga (il rinnovo di un servizio) viene pagata solo quando il credito la copre per intero, e ciò che non copre resta sull'avviso da pagare come di consueto.
<!-- if:credit_auto -->
Usiamo il Suo credito automaticamente sui nuovi avvisi di pagamento, e su quelli non pagati quando aggiunge fondi.
<!-- endif -->
<!-- if:credit_manual -->
Per usarlo, apra un avviso di pagamento non pagato e prema **Paga con il credito**.
<!-- endif -->
<!-- endif -->

<!-- if:orders -->
## Ordinare

**Ricerca dominio** (nel menu, dopo Domini) trova un nome per Lei: lo digiti (ilmionegozio, o ilmionegozio.it) e lo vedrà con ogni estensione che registriamo, se è libero o già registrato, e il suo prezzo. **Aggiungi al carrello** mette nel carrello uno libero (**Con SSL** aggiunge anche un certificato, quando lo offriamo); per uno che è Suo ma si trova altrove, **È mio: trasferirlo** apre l'ordine per il trasferimento. Potrebbe esserLe chiesto prima di confermare di non essere un robot. “Probabilmente disponibile” significa che in quel momento non è stato possibile interrogare il registro; lo verifichiamo di nuovo al momento della registrazione. Quando lo offriamo, **Suggerisci altri nomi** sotto i risultati elenca nomi liberi simili al Suo, suggeriti dall'IA, con la stessa estensione.

<!-- if:domain_prices -->
**Veda i prezzi di ogni estensione di dominio** sotto la casella di ricerca elenca ogni estensione che vendiamo con il suo prezzo di registrazione, trasferimento e rinnovo.
<!-- endif -->

**Ordine** mostra cosa può ordinare; anche **Ordina un nuovo servizio** sopra i Suoi Servizi lo apre (**Ordina un dominio** sopra i Suoi Domini apre la ricerca dominio). Quando vendiamo più tipi di servizio, scelga prima il tipo (come hosting condiviso o VPS, o nomi di dominio), poi un pacchetto: **Confronta i pacchetti** sotto di essi mostra affiancato cosa include ciascuno, con il prezzo per ogni periodo di fatturazione, e i suoi pulsanti **Scegli** selezionano anche un pacchetto. I badge accanto al nome di un pacchetto (come Popolare o Nuovo) indicano cosa consigliamo o cosa è nuovo. Scelga un servizio e il suo periodo di fatturazione, e le eventuali opzioni offerte (come una località o indirizzi IP aggiuntivi); ogni opzione mostra quanto aggiunge al prezzo. Gli add-on (come i backup giornalieri) si possono spuntare sotto; sono sullo stesso avviso di pagamento e si rinnovano insieme al servizio. Per l'hosting, indichi se ha già il dominio, se vuole registrarne uno nuovo o trasferirne uno che ha altrove (serve il suo codice EPP). Può anche ordinare un dominio da solo, con un certificato SSL quando lo offriamo (lo spunti sotto il dominio). Una volta digitato un dominio da registrare o trasferire, vede accanto se è libero; un dominio già registrato può solo essere trasferito, e uno non ancora registrato solo registrato.

Prema **Aggiungi al carrello** e si apre il **Carrello** (lo apre anche l'icona del carrello in cima a ogni pagina, con il numero di articoli). Prema **Ordina qualcos'altro** per aggiungere altro, come due domini e tre piani di hosting, o il cestino accanto a un articolo per toglierlo. Tutto ciò che è nel carrello diventa un unico ordine con un unico avviso di pagamento.

Se ha un **codice coupon**, lo inserisca nel carrello: vedrà quanto sconta prima di inviare l'ordine (un link con un codice lo compila per Lei). Un coupon può ridurre solo il primo pagamento oppure anche ogni rinnovo, e non riduce mai un costo di attivazione.

Ciò che è **in offerta** mostra il badge Offerta e il prezzo normale barrato. Il prezzo in offerta vale per il primo periodo di ciò che ordina mentre l'offerta è attiva; i rinnovi sono al prezzo normale. Un coupon potrebbe non ridurre ulteriormente un prezzo in offerta.

<!-- if:terms -->
Prima di effettuarlo, legga le nostre Condizioni di servizio (il link accanto alla casella) e spunti **Accetto le Condizioni di servizio**.
<!-- endif -->

Quando effettua l'ordine, Le inviamo via email il relativo avviso di pagamento. Attiviamo i servizi appena l'ordine è pronto, e in quel momento verifichiamo e registriamo il dominio.
<!-- endif -->

## I Suoi dati

Il Suo nome, azienda, dati IVA e indirizzo appaiono sui Suoi avvisi di pagamento e come titolare dei Suoi domini. Può chiedere di modificarli dal Suo profilo; esaminiamo la modifica e la approviamo. Email e telefono cambiano subito, così come i dati aggiuntivi che potremmo chiederLe in Altri dettagli.

Le nostre novità e offerte terminano con un link per interromperle; le email sui Suoi servizi, gli avvisi di pagamento e i promemoria continuano ad arrivare.

<!-- if:knowledge_base -->
## Knowledge base

In Supporto → Knowledge base trova le risposte alle domande frequenti, raggruppate per argomento. Digiti qualche parola nella ricerca per trovare un articolo; li trova con o senza accenti. Alla fine di ogni articolo, ci dica se Le è stato utile.
<!-- if:tickets -->
Mentre scrive l'oggetto di un nuovo ticket, sotto di esso appaiono gli articoli che potrebbero già rispondere.
<!-- endif -->

<!-- endif -->
<!-- if:tickets -->
## Ticket di supporto

In Supporto → Ticket, **Apri un ticket** per chiederci qualsiasi cosa: scelga il reparto e il servizio a cui si riferisce, scriva la Sua domanda e alleghi file come screenshot. Rispondiamo sul ticket e via email; prema **Rispondi** per scriverci di nuovo. Lo chiuda quando non ha bisogno di altro. Un ticket chiuso si riapre se risponde, e uno a cui abbiamo risposto si chiude da solo se non riceviamo Sue notizie per qualche giorno.
<!-- endif -->
<!-- if:ticket_mail -->
Può anche rispondere alle email dei nostri ticket dalla Sua casella di posta: la Sua risposta viene aggiunta al ticket, con i file allegati. Un'email inviata al nostro indirizzo di supporto apre un nuovo ticket.
<!-- endif -->

<!-- if:announcements -->
## Annunci

In Supporto → Annunci pubblichiamo novità sui nostri servizi: nuovi servizi, cambi di prezzo e manutenzioni programmate, dalle più recenti.
<!-- endif -->
<!-- if:server_status -->
## Stato dei server

Supporto → Stato dei server mostra se i nostri server funzionano in questo momento, con i quadratini degli ultimi 30 giorni e delle ultime 24 ore. Quando uno ha un problema, appare in cima con ciò che sappiamo.
<!-- endif -->
<!-- if:contact_page -->
## Contattaci

Supporto → Contattaci riporta il nostro indirizzo, telefono, email e orari di apertura, come li abbiamo inseriti. Prema un numero di telefono o un indirizzo email per chiamare o scrivere, e **Apri nelle mappe** per trovarci.
<!-- endif -->

## Domande

Ci scriva a {company_email}.
