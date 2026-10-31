## Anmelden

Melden Sie sich mit Ihrer E-Mail-Adresse und Ihrem Passwort an. Wenn Sie das Passwort vergessen, klicken Sie auf der Anmeldeseite auf „Passwort vergessen?“, und wir senden Ihnen per E-Mail einen Link, um ein neues zu wählen. Der Link funktioniert einmal.

<!-- if:google_login -->
Wenn Ihre E-Mail-Adresse ein Google-Konto ist, können Sie auf der Anmeldeseite auch auf **Weiter mit Google** klicken, statt Ihr Passwort einzugeben.
<!-- endif -->

In Ihrem Profil (das Menü mit Ihrem Namen, unten im Menü oder oben rechts) ändern Sie Ihre E-Mail-Adresse, Telefonnummer, Sprache und Ihr Passwort und können für zusätzliche Sicherheit die Zwei-Faktor-Authentifizierung einschalten.

<!-- if:notifications -->
Die **Glocke** oben auf der Seite zeigt, was seit Ihrem letzten Besuch passiert ist: eine neue Zahlungsaufforderung, eine Zahlungserinnerung oder Mahnung, eine bei uns eingegangene Zahlung, ein Dienst, der bereit, gesperrt oder beendet ist, eine Domain, die bald verlängert wird, ein neuer Preis, unsere Antwort auf Ihr Ticket oder auf eine Anfrage. Eine Zahl darauf zeigt, wie viele Sie noch nicht gelesen haben; klicken Sie auf eine, um ihre Seite zu öffnen. Auf einem Handy oder Tablet wischen Sie eine nach links oder rechts, um sie zu entfernen. Um sie abzustellen, schalten Sie **Benachrichtigungen** in Ihrem Profil aus. Wir senden Ihnen weiterhin wie bisher E-Mails.

<!-- endif -->
<!-- if:email_history -->
**E-Mail-Verlauf** im selben Menü listet die E-Mails, die wir Ihnen gesendet haben, die neuesten zuerst: Klicken Sie auf eine, um sie erneut zu lesen. E-Mails mit einem Passwort oder einem Anmeldelink werden ohne ihren Text aufgeführt; verwenden Sie stattdessen „Passwort vergessen?“ auf der Anmeldeseite.

<!-- endif -->
**Geräte und Anmeldungen** im selben Menü listet die Browser, die in Ihrem Konto angemeldet sind, und Ihre letzten Anmeldungen, einschließlich falscher Passwörter. Klicken Sie neben einem Gerät, das Sie nicht mehr verwenden, auf **Abmelden** oder auf **Andere Geräte abmelden**, um sich überall außer hier abzumelden. Wenn Sie eine Anmeldung sehen, die Sie nicht erkennen, ändern Sie auch Ihr Passwort.

<!-- if:cookies -->
„Cookie-Einstellungen“ am Fuß jeder Seite zeigt die Cookie-Auswahl erneut, damit Sie sie ändern können.
<!-- endif -->

<!-- if:welcome_tour -->
Beim ersten Login zeigt eine kurze Tour, was Sie hier tun können. Das Menü unter Ihrem Namen → **Tour** zeigt sie erneut.
<!-- endif -->

## Startseite

Die Startseite zeigt, was Sie zu zahlen haben, wie viele Dienste aktiv sind und was als Nächstes verlängert wird, jeweils mit einem Link. Unsere Ankündigungen, etwa geplante Arbeiten an Ihrem Server, erscheinen oben auf der Startseite, solange sie gelten.

## Dienste und Domains

**Dienste** listet Ihr Hosting und Ihre anderen Dienste mit Status, Preis und nächstem Fälligkeitsdatum. Das Menü teilt sie in **Hosting** und **Andere Dienste** auf. Die Seite eines Hosting-Kontos zeigt seinen Abrechnungszeitraum und hat eine Schaltfläche **Website öffnen**. **Domains** listet Ihre Domains mit Ablaufdatum, Nameservern und der Angabe, ob sie automatisch verlängert werden. Wenn Sie Dienste mit mehr als einem Status haben, zeigen die Schaltflächen über jeder Liste nur die mit einem Status (Aktiv, Gesperrt, …), mit ihrer Anzahl. Einmalige Arbeiten, etwa der Umzug Ihrer Website, werden einmal abgerechnet und erscheinen nach der Zahlung als **Abgeschlossen**.

<!-- if:hosting -->
Bei einem aktiven Hosting-Konto können Sie:

<!-- if:hosting_login -->
- **Beim Control Panel anmelden** (cPanel oder Plesk), ohne dessen Passwort einzugeben: Die Schaltfläche öffnet es in einem neuen Tab.
- Mit den **Schnellzugriffen** (bei cPanel und Plesk) eine Seite des Control Panels direkt öffnen, etwa E-Mail-Konten, den Dateimanager oder Datenbanken, bereits angemeldet.
<!-- endif -->
<!-- if:hosting_usage -->
- **Sehen, wie viel Speicherplatz und Traffic es** diesen Monat nutzt, im Verhältnis zu dem, was Ihr Paket erlaubt.
<!-- endif -->
<!-- if:hosting_uptime -->
- **Die Verfügbarkeit des Servers sehen**: wie viel der letzten 30 Tage und 24 Stunden der Server, auf dem Ihr Konto liegt, erreichbar war. In der Hosting-Liste zeigt ein Punkt vor der Domain den aktuellen Zustand des Servers: grün, solange er funktioniert, rot, solange er nicht antwortet, orange während einer Wartung. Zeigen Sie darauf, um die Verfügbarkeit der letzten 30 Tage zu sehen.
<!-- endif -->
<!-- if:vps_power -->
- **Einen VPS starten, herunterfahren oder neu starten** mit den Schaltflächen auf seiner Seite. Herunterfahren und Neu starten beenden die darauf laufenden Programme, speichern Sie also zuerst Ihre Arbeit. Um seine Konsole zu verwenden, klicken Sie auf **Bei Proxmox VE anmelden** und melden Sie sich mit Benutzername und Passwort des Dienstes an (Realm „Proxmox VE authentication server“): Sie sehen nur Ihren eigenen VPS.
<!-- endif -->
<!-- if:hosting_password -->
- **Das Passwort** des Kontos **ändern**. Verwenden Sie mindestens 10 Zeichen mit Groß- und Kleinbuchstaben und Ziffern.
<!-- endif -->
<!-- endif -->

Um einen Dienst auf ein größeres oder kleineres Paket umzustellen, klicken Sie auf seiner Seite auf **Upgrade / Downgrade** und wählen Sie das neue; jedes zeigt seinen Preis für Ihren Abrechnungszeitraum. Sobald wir es genehmigen, gilt das neue Paket sofort und der neue Preis ab Ihrem nächsten Fälligkeitsdatum (wir senden Ihnen eventuell auch eine Zahlungsaufforderung für den Rest des laufenden Zeitraums). Wir bestätigen per E-Mail; bis dahin zeigt die Seite die Anfrage, und **Anfrage zur Paketänderung zurückziehen** nimmt sie zurück.

<!-- if:orders -->
Wenn für einen Dienst Add-ons verfügbar sind (etwa tägliche Backups), klicken Sie auf seiner Seite auf **Add-on hinzufügen** und wählen Sie eines; jedes zeigt seinen Preis für Ihren Abrechnungszeitraum und was Sie jetzt zahlen. Sie zahlen jetzt nur für die Tage bis zum nächsten Fälligkeitsdatum des Dienstes (plus eventuelle Einrichtungsgebühr), und danach wird das Add-on zusammen mit dem Dienst verlängert, auf derselben Zahlungsaufforderung. Die Zahlungsaufforderung öffnet sich sofort; das Add-on wird aktiv, sobald sie bezahlt ist, und die Seite listet es unter **Add-ons** (mit einem Link zur Aufforderung, solange sie wartet).

<!-- endif -->
Um ein Hosting-Konto oder einen anderen Dienst zu beenden, klicken Sie auf seiner Seite auf **Kündigung beantragen**. Nennen Sie, wenn Sie möchten, den Grund und wählen Sie, ob er **zum Ende des Abrechnungszeitraums** endet (Sie nutzen ihn bis zum nächsten Fälligkeitsdatum weiter) oder **sofort** (das Konto und seine Dateien werden gelöscht). Wenn Sie eine Domain mit demselben Namen haben, können Sie uns auch bitten, sie nicht zu verlängern. Wir bestätigen per E-Mail, sobald wir es durchgeführt haben; bis dahin zeigt die Seite die Anfrage, und **Kündigungsanfrage zurückziehen** nimmt sie zurück. Bei einer Domain schalten Sie stattdessen die automatische Verlängerung aus.

Wenn wir den Preis eines Dienstes ändern, informieren wir Sie vorab per E-Mail, und seine Seite zeigt den neuen Preis unter **Preis** mit dem Datum, ab dem er gilt (zum Beispiel *Ab 01/12/2026: €66.00*). Verlängerungen, die vor diesem Datum fällig sind, behalten den aktuellen Preis, und bereits ausgestellte Zahlungsaufforderungen ändern sich nicht.

<!-- if:incident_notice -->
Wenn ein Server, auf dem Ihr Hosting liegt, ein Problem hat oder in Wartung ist, weist ein Hinweis oben auf jeder Seite darauf hin und nennt die betroffenen Dienste. Er verschwindet von selbst, sobald wieder alles funktioniert, Sie müssen uns deshalb also nicht kontaktieren.

<!-- endif -->
<!-- if:ssl -->
Ein **SSL-Zertifikat** wird für die bei der Bestellung angegebene Domain bestellt, sobald es bezahlt ist. Seine Seite zeigt, was Sie tun müssen, um nachzuweisen, dass die Domain Ihnen gehört (die Zertifizierungsstelle prüft dies, bevor sie etwas ausstellt), und wir senden es Ihnen auch per E-Mail:

- **DNS-Eintrag (CNAME):** Fügen Sie den angezeigten Eintrag (Name, Typ und Wert) zur DNS-Zone der Domain hinzu.
- **Datei auf der Website:** Legen Sie die angezeigte Datei mit genau diesem Inhalt an, sodass sie unter der angezeigten Adresse aufrufbar ist.
- **E-Mail an den Administrator der Domain:** Öffnen Sie den Link in der E-Mail an admin@ (oder die gewählte Adresse) und genehmigen Sie.

Wenn die Domain ein Hosting-Konto ist, das Sie bei uns haben, erledigen wir das für Sie: Seine Seite zeigt, dass es bereits eingerichtet ist, und sobald das Zertifikat ausgestellt ist, installieren wir es auch auf diesem Konto, sodass Sie nichts herunterladen oder installieren müssen.

**Validierung ändern** wählt einen anderen Weg (oder eine andere Bestätigungsadresse), und **Erneut prüfen** bittet die Zertifizierungsstelle, jetzt nachzusehen (**Bestätigungs-E-Mail erneut senden**, wenn es per E-Mail geht). Sobald das Zertifikat ausgestellt ist, senden wir Ihnen eine E-Mail, und **Zertifikat herunterladen** liefert ein ZIP mit dem Zertifikat, dem CA-Bundle und, wenn wir ihn erstellt haben, dem privaten Schlüssel, bereit zur Installation auf dem Server. Ein Zertifikat gilt höchstens 200 Tage, daher stellen wir, solange Ihr Dienst läuft, vor jedem Ablauf das nächste aus: Sie validieren die Domain jedes Mal erneut und laden das neue Zertifikat herunter.

<!-- endif -->
<!-- if:domains -->
Bei einer aktiven, über uns registrierten Domain können Sie:

<!-- if:domain_nameservers -->
- **Die Nameserver ändern** (2 bis 4). Die Änderung geht sofort an die Registry; im Internet ist sie innerhalb weniger Stunden sichtbar.
<!-- endif -->
<!-- if:domain_epp -->
- **Den EPP-Code abrufen**, den Sie brauchen, um die Domain zu einem anderen Anbieter umzuziehen. Bei manchen Domains wird er am Bildschirm angezeigt, bei anderen an die E-Mail-Adresse des Domaininhabers gesendet.
<!-- endif -->
<!-- if:domain_auto_renew -->
- **Die automatische Verlängerung aus- oder einschalten.** Ist sie aus, erhalten Sie keine Zahlungsaufforderungen mehr für die Domain, und sie endet an ihrem Fälligkeitsdatum.
<!-- endif -->
<!-- if:domain_contacts -->
- **Die Kontaktdaten ändern** (Inhaber, Verwaltungs-, Technik- und bei manchen Domains Abrechnungskontakt), wie die Registry sie führt. **Meine Kontodaten verwenden** füllt einen Kontakt aus Ihrem Profil aus, und **Wie der Inhaber** übernimmt die Daten des Inhabers. Manche Registries (etwa .gr) erlauben auf diesem Weg keine Änderung des Inhabernamens: Kontaktieren Sie uns dafür.
<!-- endif -->
<!-- if:domain_child_nameservers -->
- **Private Nameserver registrieren**, die nach Ihrer Domain benannt sind (etwa ns1.ihredomain.gr), mit ihrer IP-Adresse, ihre IP ändern oder sie löschen.
<!-- endif -->
<!-- if:domain_lock -->
- **Die Domain sperren oder entsperren** (Registrar-Sperre). Eine gesperrte Domain kann nicht zu einem anderen Anbieter umgezogen werden; entsperren Sie sie erst kurz vor einem Transfer. Nicht jede Registry hat eine Sperre.
<!-- endif -->
<!-- endif -->

## Zahlungsaufforderungen und Zahlungen

Wenn ein Dienst fällig ist, senden wir Ihnen per E-Mail eine **Zahlungsaufforderung** mit der Zahlungsweise. Sie ist eine Zahlungsaufforderung, keine Rechnung im steuerlichen Sinn. **Zahlungsaufforderungen** listet sie alle: Öffnen Sie eine, um ihre Positionen zu sehen, sie zu drucken oder als PDF zu speichern. Die Schaltflächen über der Liste zeigen nur die unbezahlten, bezahlten oder überfälligen. **Zahlungen** listet die Zahlungen, die wir erfasst haben. Nach Ihrer Zahlung wird die Aufforderung als bezahlt angezeigt, sobald wir Ihre Zahlung erfassen.

<!-- if:invoices -->
Wenn wir Rechnungen ausstellen, erhält eine bezahlte Zahlungsaufforderung auch eine **Rechnung**: Wir senden sie Ihnen als PDF per E-Mail, die bezahlte Aufforderung hat eine Schaltfläche zum Herunterladen, und **Rechnungen** (unter Abrechnung) listet sie alle auf. Eine **Gutschrift** dort, mit negativem Betrag, macht eine Rechnung rückgängig, etwa wenn eine Zahlung zurückerstattet wird.
<!-- endif -->

<!-- if:pay_card -->
Um per Karte zu bezahlen, öffnen Sie eine unbezahlte Zahlungsaufforderung und klicken Sie auf **Mit Karte bezahlen**. Sie zahlen auf der sicheren Zahlungsseite von Stripe (wir sehen Ihre Kartendaten nie), und die Aufforderung wird als bezahlt angezeigt, sobald die Zahlung durchgeführt ist.
<!-- endif -->

<!-- if:pay_paypal -->
Um mit PayPal zu bezahlen, öffnen Sie eine unbezahlte Zahlungsaufforderung und klicken Sie auf **Mit PayPal bezahlen**. Sie bestätigen die Zahlung auf der Seite von PayPal, mit Ihrem PayPal-Guthaben oder einer Karte, und die Aufforderung wird als bezahlt angezeigt, sobald PayPal die Zahlung bestätigt.
<!-- endif -->

<!-- if:credit -->
## Angebote

Wenn wir Ihnen ein Angebot senden, erhalten Sie eine E-Mail mit einem Link dazu, und **Angebote** (unter Abrechnung) listet die Angebote, die wir Ihnen gesendet haben, mit ihrer ersten Zahlung und ihrer Gültigkeitsdauer. Klicken Sie auf eines, um es zu öffnen: Dort können Sie es drucken. **Annehmen** gibt die Bestellung zu den Preisen des Angebots auf (mit unseren Geschäftsbedingungen, wenn wir Sie bitten, sie zu akzeptieren), und ihre Zahlungsaufforderung wird Ihnen mit der Zahlungsweise per E-Mail gesendet. **Ablehnen** teilt uns mit, dass Sie es nicht möchten; eine Begründung hilft uns. Ist sein Datum verstrichen, kann ein Angebot nicht mehr angenommen werden: Antworten Sie auf unsere E-Mail, und wir senden Ihnen ein neues.

## Guthaben

**Guthaben** zeigt Ihr Guthaben und seinen Verlauf. Um Guthaben aufzuladen, klicken Sie auf **Guthaben aufladen** und geben Sie einen Betrag ein: Wir erstellen dafür eine Zahlungsaufforderung, die Sie wie jede andere bezahlen. Sobald sie bezahlt ist, wird der Betrag Ihrem Guthaben hinzugefügt. Sie können Guthaben aufladen, sobald Sie einen aktiven Dienst haben.

Das Guthaben bezahlt Ihre Zahlungsaufforderungen Position für Position: Eine Position (die Verlängerung eines Dienstes) wird nur bezahlt, wenn Ihr Guthaben sie vollständig deckt, und was es nicht deckt, bleibt wie üblich zur Zahlung auf der Aufforderung.
<!-- if:credit_auto -->
Wir verwenden Ihr Guthaben automatisch für neue Zahlungsaufforderungen und für unbezahlte, wenn Sie Guthaben aufladen.
<!-- endif -->
<!-- if:credit_manual -->
Um es zu verwenden, öffnen Sie eine unbezahlte Zahlungsaufforderung und klicken Sie auf **Mit Guthaben bezahlen**.
<!-- endif -->
<!-- endif -->

<!-- if:orders -->
## Bestellen

**Domainsuche** (im Menü, nach Domains) findet einen Namen für Sie: Geben Sie ihn ein (meinshop oder meinshop.gr), und Sie sehen ihn mit jeder Endung, die wir registrieren, ob er frei oder vergeben ist, und seinen Preis. **In den Warenkorb** legt einen freien in den Warenkorb (**Mit SSL** fügt auch ein Zertifikat dafür hinzu, wenn wir eines anbieten); für einen, der Ihnen gehört, aber anderswo liegt, öffnet **Meine: transferieren** die Bestellung für den Transfer. Eventuell werden Sie zuerst gebeten zu bestätigen, dass Sie kein Roboter sind. „Wahrscheinlich verfügbar“ bedeutet, dass die Registry gerade nicht abgefragt werden konnte; wir prüfen es bei der Registrierung erneut. Wenn wir es anbieten, listet **Andere Namen vorschlagen** unter den Ergebnissen freie Namen ähnlich Ihrem, von KI vorgeschlagen, mit derselben Endung.

<!-- if:domain_prices -->
**Preise aller Domainendungen ansehen** unter dem Suchfeld listet jede Endung, die wir verkaufen, mit ihrem Preis für Registrierung, Transfer und Verlängerung.
<!-- endif -->

**Bestellung** zeigt, was Sie bestellen können; **Einen neuen Dienst bestellen** über Ihren Diensten öffnet sie ebenfalls (**Eine Domain bestellen** über Ihren Domains öffnet die Domainsuche). Wenn wir mehrere Arten von Diensten verkaufen, wählen Sie zuerst die Art (etwa Shared Hosting oder ein VPS oder Domainnamen), dann ein Paket: **Pakete vergleichen** darunter zeigt nebeneinander, was jedes enthält, mit seinem Preis für jeden Abrechnungszeitraum, und seine Schaltflächen **Wählen** wählen ebenfalls ein Paket. Abzeichen neben dem Namen eines Pakets (etwa Beliebt oder Neu) zeigen, was wir empfehlen oder was neu ist. Wählen Sie einen Dienst und seinen Abrechnungszeitraum sowie die angebotenen Optionen (etwa einen Standort oder zusätzliche IP-Adressen); jede Option zeigt, was sie zum Preis hinzufügt. Add-ons dafür (etwa tägliche Backups) können darunter angehakt werden; sie stehen auf derselben Zahlungsaufforderung und werden zusammen damit verlängert. Geben Sie bei Hosting an, ob Sie die Domain bereits haben, eine neue registrieren oder eine von anderswo umziehen möchten (dafür brauchen Sie ihren EPP-Code). Sie können auch eine Domain allein bestellen, mit einem SSL-Zertifikat dafür, wenn wir eines anbieten (haken Sie es unter der Domain an). Sobald Sie eine Domain zur Registrierung oder zum Transfer eingeben, sehen Sie daneben, ob sie frei ist; eine vergebene Domain kann nur transferiert, eine noch nicht registrierte nur registriert werden.

Klicken Sie auf **In den Warenkorb**, und der **Warenkorb** öffnet sich (das Warenkorb-Symbol oben auf jeder Seite öffnet ihn ebenfalls, mit der Anzahl der Artikel darin). Klicken Sie auf **Etwas anderes bestellen**, um mehr hinzuzufügen, etwa zwei Domains und drei Hosting-Tarife, oder auf den Papierkorb neben einem Artikel, um ihn zu entfernen. Alles im Warenkorb wird zu einer Bestellung mit einer Zahlungsaufforderung.

Wenn Sie einen **Gutscheincode** haben, geben Sie ihn im Warenkorb ein: Sie sehen, was er abzieht, bevor Sie die Bestellung aufgeben (ein Link mit einem Code füllt ihn für Sie aus). Ein Gutschein kann nur die erste Zahlung oder auch jede Verlängerung senken, aber nie eine Einrichtungsgebühr.

Was im **Angebot** ist, trägt das Abzeichen Angebot und zeigt seinen normalen Preis durchgestrichen. Der Angebotspreis gilt für den ersten Zeitraum dessen, was Sie bestellen, solange das Angebot läuft; Verlängerungen kosten den normalen Preis. Ein Gutschein senkt einen Angebotspreis eventuell nicht weiter.

<!-- if:terms -->
Bevor Sie sie aufgeben, lesen Sie unsere Geschäftsbedingungen (der Link neben dem Feld) und haken Sie **Ich akzeptiere die Geschäftsbedingungen** an.
<!-- endif -->

Wenn Sie die Bestellung aufgeben, senden wir Ihnen ihre Zahlungsaufforderung per E-Mail. Wir richten die Dienste ein, sobald die Bestellung bereit ist, und prüfen und registrieren dann die Domain.
<!-- endif -->

## Ihre Daten

Ihr Name, Ihre Firma, Ihre USt-Angaben und Ihre Adresse erscheinen auf Ihren Zahlungsaufforderungen und als Inhaber Ihrer Domains. Sie können in Ihrem Profil eine Änderung beantragen; wir prüfen die Änderung und genehmigen sie. E-Mail und Telefon ändern sich sofort, ebenso die zusätzlichen Angaben, die wir eventuell unter Weitere Angaben erfragen.

Unsere Neuigkeiten und Angebote enden mit einem Link zum Abbestellen; E-Mails zu Ihren Diensten, Zahlungsaufforderungen und Erinnerungen kommen weiterhin.

<!-- if:knowledge_base -->
## Wissensdatenbank

Unter Support → Wissensdatenbank finden Sie Antworten auf häufige Fragen, nach Themen gruppiert. Geben Sie einige Wörter in die Suche ein, um einen Artikel zu finden; sie findet ihn mit oder ohne Akzente. Am Ende jedes Artikels teilen Sie uns mit, ob er geholfen hat.
<!-- if:tickets -->
Während Sie den Betreff eines neuen Tickets schreiben, erscheinen darunter Artikel, die es vielleicht schon beantworten.
<!-- endif -->

<!-- endif -->
<!-- if:tickets -->
## Support-Tickets

Unter Support → Tickets können Sie mit **Ticket eröffnen** uns alles fragen: Wählen Sie die Abteilung und den betreffenden Dienst, schreiben Sie Ihre Frage und hängen Sie Dateien wie Screenshots an. Wir antworten im Ticket und per E-Mail; klicken Sie darin auf **Antworten**, um zurückzuschreiben. Schließen Sie es, wenn Sie nichts mehr brauchen. Ein geschlossenes Ticket öffnet sich wieder, wenn Sie antworten, und eines, das wir beantwortet haben, schließt sich von selbst, wenn wir einige Tage nichts von Ihnen hören.
<!-- endif -->
<!-- if:ticket_mail -->
Sie können auf unsere Ticket-E-Mails auch aus Ihrem eigenen Postfach antworten: Ihre Antwort wird dem Ticket hinzugefügt, mit ihren angehängten Dateien. Eine E-Mail an unsere Support-Adresse eröffnet ein neues Ticket.
<!-- endif -->

<!-- if:announcements -->
## Ankündigungen

Unter Support → Ankündigungen veröffentlichen wir Neuigkeiten zu unseren Diensten: neue Dienste, Preisänderungen und geplante Wartungen, die neuesten zuerst.
<!-- endif -->
<!-- if:server_status -->
## Serverstatus

Support → Serverstatus zeigt, ob unsere Server gerade funktionieren, mit den Quadraten der letzten 30 Tage und 24 Stunden. Hat einer ein Problem, steht er oben mit dem, was wir darüber wissen.
<!-- endif -->
<!-- if:contact_page -->
## Kontaktieren Sie uns

Support → Kontaktieren Sie uns enthält unsere Adresse, Telefonnummer, E-Mail und Öffnungszeiten, wie wir sie eingetragen haben. Klicken Sie auf eine Telefonnummer oder E-Mail-Adresse, um anzurufen oder zu schreiben, und auf **In Karten öffnen**, um uns zu finden.
<!-- endif -->

## Fragen

Schreiben Sie uns an {company_email}.
