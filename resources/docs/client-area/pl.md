## Logowanie

Logują się Państwo adresem e-mail i hasłem. W razie zapomnienia hasła prosimy kliknąć „Nie pamiętasz hasła?” na stronie logowania, a wyślemy e-mailem link do ustawienia nowego. Link działa jeden raz.

<!-- if:google_login -->
Jeśli Państwa adres e-mail jest kontem Google, można też kliknąć **Kontynuuj z Google** na stronie logowania zamiast wpisywać hasło.
<!-- endif -->

W profilu (menu z Państwa imieniem, na dole menu lub w prawym górnym rogu) można zmienić adres e-mail, numer telefonu, język i hasło oraz włączyć uwierzytelnianie dwuskładnikowe dla większego bezpieczeństwa.

<!-- if:notifications -->
**Dzwonek** u góry strony informuje, co się wydarzyło od ostatniego sprawdzenia: nowe wezwanie do zapłaty, przypomnienie o płatności lub powiadomienie o zaległości, otrzymana przez nas płatność, usługa gotowa, zawieszona lub zakończona, domena przed odnowieniem, nowa cena, nasza odpowiedź na zgłoszenie lub prośbę. Liczba na nim pokazuje, ile jest nieprzeczytanych; kliknięcie powiadomienia otwiera jego stronę. Aby je wyłączyć, prosimy wyłączyć **Powiadomienia** w profilu. E-maile nadal będą wysyłane jak dotychczas.

<!-- endif -->
<!-- if:email_history -->
**Historia e-maili**, w tym samym menu, wymienia wysłane przez nas e-maile, od najnowszych: kliknięcie otwiera e-mail ponownie. E-maile z hasłem lub linkiem logowania są wymienione bez treści; zamiast tego prosimy użyć „Nie pamiętasz hasła?” na stronie logowania.

<!-- endif -->
**Urządzenia i logowania**, w tym samym menu, wymienia przeglądarki zalogowane na Państwa konto i ostatnie logowania, łącznie z błędnymi hasłami. Kliknięcie **Wyloguj** obok nieużywanego już urządzenia lub **Wyloguj inne urządzenia** wylogowuje wszędzie poza bieżącym. Jeśli widzą Państwo nierozpoznane logowanie, prosimy też zmienić hasło.

<!-- if:cookies -->
„Ustawienia cookies” u dołu każdej strony ponownie pokazuje wybór dotyczący cookies, aby można go było zmienić.
<!-- endif -->

<!-- if:welcome_tour -->
Przy pierwszym logowaniu krótki przewodnik pokazuje, co możesz tu zrobić. Menu pod Twoim imieniem → **Przewodnik** pokazuje go ponownie.
<!-- endif -->

## Strona główna

Strona główna pokazuje, ile jest do zapłaty, ile usług jest aktywnych i co odnawia się najbliżej, z linkiem do każdej pozycji. Nasze ogłoszenia, np. o planowanych pracach na Państwa serwerze, pojawiają się u góry Strony głównej, dopóki obowiązują.

## Usługi i domeny

**Usługi** wymieniają Państwa hosting i inne usługi z ich statusem, ceną i następnym terminem płatności. Menu dzieli je na **Hosting** i **Inne usługi**. Strona konta hostingowego pokazuje jego okres rozliczeniowy i ma przycisk **Otwórz stronę WWW**. **Domeny** wymieniają Państwa domeny z datą wygaśnięcia, serwerami nazw i informacją, czy odnawiają się automatycznie. Gdy usługi mają różne statusy, przyciski nad każdą listą pokazują tylko te o jednym statusie (Aktywna, Zawieszona, …), z ich liczbą. Praca wykonywana jednorazowo, np. przeniesienie strony, jest rozliczana raz i po opłaceniu ma status **Zakończone**.

<!-- if:hosting -->
Przy aktywnym koncie hostingowym można:

<!-- if:hosting_login -->
- Kliknąć **Zaloguj do panelu sterowania** (cPanel lub Plesk), aby wejść bez wpisywania hasła: przycisk otwiera go w nowej karcie.
- Użyć **Szybkich skrótów** (w cPanel i Plesk), aby od razu otworzyć stronę panelu sterowania, np. konta e-mail, menedżer plików lub bazy danych, już po zalogowaniu.
<!-- endif -->
<!-- if:hosting_usage -->
- **Sprawdzić, ile miejsca na dysku i transferu zużywa** w tym miesiącu, w porównaniu z tym, na co pozwala pakiet.
<!-- endif -->
<!-- if:hosting_uptime -->
- **Sprawdzić dostępność serwera**: przez jaką część ostatnich 30 dni i 24 godzin działał serwer, na którym jest konto. Na liście Hosting kropka przed domeną pokazuje obecny stan serwera: zielona, gdy działa, czerwona, gdy nie odpowiada, pomarańczowa podczas prac konserwacyjnych. Po najechaniu na nią widać dostępność z ostatnich 30 dni.
<!-- endif -->
<!-- if:vps_power -->
- **Uruchomić, wyłączyć lub uruchomić ponownie VPS** przyciskami na jego stronie. Wyłącz i Uruchom ponownie zatrzymują działające na nim programy, więc prosimy najpierw zapisać pracę. Aby użyć konsoli, prosimy kliknąć **Zaloguj do Proxmox VE** i zalogować się nazwą użytkownika i hasłem usługi (realm „Proxmox VE authentication server”): widoczny jest tylko Państwa VPS.
<!-- endif -->
<!-- if:hosting_password -->
- **Zmienić hasło** konta. Prosimy użyć co najmniej 10 znaków z wielkimi i małymi literami oraz cyframi.
<!-- endif -->
<!-- endif -->

Aby przenieść usługę na większy lub mniejszy pakiet, prosimy kliknąć **Zmiana pakietu** na jej stronie i wybrać nowy; każdy pokazuje swoją cenę dla Państwa okresu rozliczeniowego. Po naszym zatwierdzeniu nowy pakiet obowiązuje od razu, a nowa cena od następnego terminu płatności (możemy też wysłać wezwanie do zapłaty za resztę bieżącego okresu). Potwierdzamy to e-mailem; do tego czasu strona pokazuje prośbę, a **Wycofaj prośbę o zmianę pakietu** ją wycofuje.

<!-- if:orders -->
Gdy dla usługi dostępne są dodatki (np. codzienne kopie zapasowe), prosimy kliknąć **Dodaj dodatek** na jej stronie i wybrać jeden; każdy pokazuje swoją cenę dla Państwa okresu rozliczeniowego i kwotę do zapłaty teraz. Teraz płacą Państwo tylko za dni do następnego terminu płatności usługi (plus ewentualna opłata aktywacyjna), a potem dodatek odnawia się razem z usługą, na tym samym wezwaniu do zapłaty. Wezwanie do zapłaty otwiera się od razu; dodatek staje się aktywny po jego opłaceniu, a strona wymienia go w sekcji **Dodatki** (z linkiem do wezwania, dopóki czeka na płatność).

<!-- endif -->
Aby zakończyć konto hostingowe lub inną usługę, prosimy kliknąć **Poproś o rezygnację** na jej stronie. Można podać powód i wybrać, czy usługa ma się zakończyć **na koniec okresu rozliczeniowego** (można z niej korzystać do następnego terminu płatności), czy **natychmiast** (konto i jego pliki zostaną usunięte). Jeśli mają Państwo domenę o tej samej nazwie, można też poprosić, abyśmy jej nie odnawiali. Potwierdzamy e-mailem po wykonaniu; do tego czasu strona pokazuje prośbę, a **Wycofaj prośbę o rezygnację** ją wycofuje. W przypadku domeny zamiast tego prosimy wyłączyć automatyczne odnawianie.

Gdy zmieniamy cenę usługi, informujemy o tym wcześniej e-mailem, a jej strona pokazuje nową cenę w polu **Cena** wraz z datą, od której obowiązuje (na przykład *Od 01/12/2026: 66,00 €*). Odnowienia z terminem przed tą datą zachowują obecną cenę, a już wystawione wezwania do zapłaty się nie zmieniają.

<!-- if:incident_notice -->
Jeśli serwer, na którym jest Państwa hosting, ma problem lub jest w trakcie prac konserwacyjnych, powiadomienie u góry każdej strony o tym informuje i wymienia usługi, których to dotyczy. Znika samo, gdy wszystko znów działa, więc nie trzeba się z nami w tej sprawie kontaktować.

<!-- endif -->
<!-- if:ssl -->
**Certyfikat SSL** jest zamawiany dla domeny podanej przy zamówieniu, po jego opłaceniu. Jego strona pokazuje, co zrobić, aby udowodnić, że domena należy do Państwa (urząd certyfikacji sprawdza to przed wystawieniem czegokolwiek), a my wysyłamy to także e-mailem:

- **Rekord DNS (CNAME):** prosimy dodać pokazany rekord (nazwa, typ i wartość) do strefy DNS domeny.
- **Plik w witrynie:** prosimy utworzyć pokazany plik, z dokładnie taką zawartością, aby otwierał się pod pokazanym adresem.
- **E-mail do administratora domeny:** prosimy otworzyć link w e-mailu wysłanym na admin@ (lub wybrany adres) i zatwierdzić.

Gdy domena jest kontem hostingowym, które mają Państwo u nas, robimy to za Państwa: jej strona informuje, że wszystko jest już na miejscu, a po wydaniu certyfikatu instalujemy go także na tym koncie, więc nie trzeba niczego pobierać ani instalować.

**Zmień weryfikację** wybiera inny sposób (lub inny adres zatwierdzający), a **Sprawdź ponownie** prosi urząd certyfikacji o sprawdzenie teraz (**Wyślij ponownie e-mail zatwierdzający**, gdy weryfikacja jest e-mailowa). Po wystawieniu certyfikatu wysyłamy e-mail, a **Pobierz certyfikat** daje plik zip z certyfikatem, CA bundle i, jeśli to my go utworzyliśmy, kluczem prywatnym, gotowy do instalacji na serwerze. Certyfikat jest ważny najwyżej 200 dni, więc dopóki usługa działa, wystawiamy kolejny przed wygaśnięciem poprzedniego: za każdym razem należy ponownie zweryfikować domenę i pobrać nowy certyfikat.

<!-- endif -->
<!-- if:domains -->
Przy aktywnej domenie zarejestrowanej przez nas można:

<!-- if:domain_nameservers -->
- **Zmienić serwery nazw** (od 2 do 4). Zmiana trafia do rejestru od razu; internet widzi ją w ciągu kilku godzin.
<!-- endif -->
<!-- if:domain_epp -->
- **Pobrać kod EPP** potrzebny do przeniesienia domeny do innego dostawcy. Dla niektórych domen jest pokazywany na ekranie; dla innych wysyłany na adres e-mail właściciela domeny.
<!-- endif -->
<!-- if:domain_auto_renew -->
- **Wyłączyć lub włączyć automatyczne odnawianie.** Gdy jest wyłączone, nie przychodzą już wezwania do zapłaty za domenę, a domena kończy się w terminie płatności.
<!-- endif -->
<!-- if:domain_contacts -->
- **Zmienić dane kontaktowe** (właściciel, kontakt administracyjny, techniczny i, dla niektórych domen, rozliczeniowy) w postaci, w jakiej ma je rejestr. **Użyj danych mojego konta** uzupełnia kontakt z profilu, a **Taki sam jak właściciel** kopiuje dane właściciela. Niektóre rejestry (np. .gr) nie pozwalają w ten sposób zmienić nazwy właściciela: w tej sprawie prosimy o kontakt.
<!-- endif -->
<!-- if:domain_child_nameservers -->
- **Zarejestrować prywatne serwery nazw** nazwane od Państwa domeny (np. ns1.twojadomena.pl) z ich adresem IP, zmienić ich IP lub je usunąć.
<!-- endif -->
<!-- if:domain_lock -->
- **Zablokować lub odblokować domenę** (blokada u rejestratora). Zablokowanej domeny nie można przenieść do innego dostawcy; prosimy odblokować ją tylko tuż przed transferem. Nie każdy rejestr ma blokadę.
<!-- endif -->
<!-- endif -->

## Wezwania do zapłaty i płatności

Gdy zbliża się termin płatności usługi, wysyłamy e-mailem **wezwanie do zapłaty** z informacją, jak zapłacić. Jest to wezwanie do zapłaty, a nie faktura. **Wezwania do zapłaty** wymieniają je wszystkie: po otwarciu widać pozycje, można je wydrukować lub zapisać jako PDF. Przyciski nad listą pokazują tylko nieopłacone, opłacone lub zaległe. **Płatności** wymieniają zaksięgowane przez nas płatności. Po zapłacie wezwanie jest widoczne jako opłacone, gdy zaksięgujemy płatność.

<!-- if:invoices -->
Gdy wystawiamy faktury, opłacone wezwanie do zapłaty dostaje też **fakturę**: wysyłamy ją e-mailem jako PDF, opłacone wezwanie ma przycisk do jej pobrania, a **Faktury** (w Rozliczeniach) pokazują je wszystkie.
<!-- endif -->

<!-- if:pay_online -->
Aby zapłacić kartą, prosimy otworzyć nieopłacone wezwanie do zapłaty i kliknąć **Zapłać kartą**. Płatność odbywa się na bezpiecznej stronie płatności Stripe (nigdy nie widzimy danych karty), a wezwanie jest widoczne jako opłacone, gdy tylko płatność zostanie zrealizowana.
<!-- endif -->

<!-- if:credit -->
## Oferty

Gdy wysyłamy ofertę, przychodzi e-mail z linkiem do niej, a **Oferty** (w Rozliczeniach) wymieniają wysłane oferty z pierwszą płatnością i okresem ważności. Kliknięcie otwiera ofertę: można ją tam wydrukować. **Przyjmij** składa zamówienie w cenach z oferty (wraz z naszym Regulaminem, gdy prosimy o jego akceptację), a wezwanie do zapłaty z informacją, jak zapłacić, przychodzi e-mailem. **Odrzuć** informuje nas, że oferta nie jest potrzebna; podanie powodu nam pomaga. Po upływie daty ważności oferty nie można przyjąć: prosimy odpowiedzieć na nasz e-mail, a wyślemy nową.

## Saldo

**Saldo** pokazuje stan salda i jego historię. Aby je doładować, prosimy kliknąć **Doładuj saldo** i wpisać kwotę: tworzymy na nią wezwanie do zapłaty, które opłaca się jak każde inne. Po jego opłaceniu kwota zostaje dodana do salda. Saldo można doładować, gdy ma się aktywną usługę.

Saldo opłaca wezwania do zapłaty pozycja po pozycji: pozycja (odnowienie usługi) jest opłacana tylko wtedy, gdy saldo pokrywa ją w całości, a to, czego nie pokrywa, pozostaje na wezwaniu do zapłaty jak zwykle.
<!-- if:credit_auto -->
Używamy salda automatycznie przy nowych wezwaniach do zapłaty, a przy nieopłaconych po doładowaniu salda.
<!-- endif -->
<!-- if:credit_manual -->
Aby go użyć, prosimy otworzyć nieopłacone wezwanie do zapłaty i kliknąć **Zapłać z salda**.
<!-- endif -->
<!-- endif -->

<!-- if:orders -->
## Zamawianie

**Wyszukiwarka domen** (w menu, po Domenach) znajduje nazwę: wystarczy ją wpisać (mojsklep lub mojsklep.pl), a pojawi się z każdą obsługiwaną przez nas końcówką, z informacją, czy jest wolna, czy zajęta, i z ceną. **Dodaj do koszyka** dodaje wolną do koszyka (**Z SSL** dodaje do niej także certyfikat, jeśli go oferujemy); dla domeny, która należy do Państwa, ale jest gdzie indziej, **Moja: przenieś ją** otwiera zamówienie transferu. Najpierw może pojawić się prośba o potwierdzenie, że nie są Państwo robotem. „Prawdopodobnie dostępna” oznacza, że nie udało się wtedy zapytać rejestru; sprawdzimy ją ponownie przy rejestracji. Gdy to oferujemy, **Zaproponuj inne nazwy** pod wynikami wymienia wolne nazwy bliskie wyszukiwanej, zaproponowane przez AI, z tą samą końcówką.

<!-- if:domain_prices -->
**Zobacz ceny wszystkich końcówek domen** pod polem wyszukiwania wymienia każdą sprzedawaną końcówkę z ceną rejestracji, transferu i odnowienia.
<!-- endif -->

**Zamówienie** pokazuje, co można zamówić; otwiera je też **Zamów nową usługę** nad Usługami (**Zamów domenę** nad Domenami otwiera wyszukiwarkę domen). Gdy sprzedajemy kilka rodzajów usług, najpierw prosimy wybrać rodzaj (np. hosting współdzielony, VPS lub nazwy domen), a potem pakiet: **Porównaj pakiety** pod nimi pokazuje obok siebie, co obejmuje każdy, z ceną dla każdego okresu rozliczeniowego, a jego przyciski **Wybierz** również wybierają pakiet. Odznaki przy nazwie pakietu (np. Popularny lub Nowy) wskazują, co polecamy lub co jest nowe. Prosimy wybrać usługę i jej okres rozliczeniowy oraz oferowane opcje (np. lokalizację lub dodatkowe adresy IP); każda opcja pokazuje, ile dodaje do ceny. Dodatki do niej (np. codzienne kopie zapasowe) można zaznaczyć poniżej; są na tym samym wezwaniu do zapłaty i odnawiają się razem z usługą. Przy hostingu prosimy wskazać, czy mają już Państwo domenę, chcą zarejestrować nową, czy przenieść posiadaną gdzie indziej (potrzebny jest jej kod EPP). Można też zamówić samą domenę, wraz z certyfikatem SSL, gdy go oferujemy (zaznaczenie pod domeną). Po wpisaniu domeny do rejestracji lub transferu widać obok, czy jest wolna; zajętą domenę można tylko przenieść, a niezarejestrowaną tylko zarejestrować.

Po kliknięciu **Dodaj do koszyka** otwiera się **Koszyk** (otwiera go też ikona koszyka u góry każdej strony, z liczbą pozycji). **Zamów coś jeszcze** pozwala dodać więcej, np. dwie domeny i trzy plany hostingowe, a kosz obok pozycji ją usuwa. Wszystko w koszyku staje się jednym zamówieniem z jednym wezwaniem do zapłaty.

Jeśli mają Państwo **kod kuponu**, prosimy wpisać go w koszyku: przed złożeniem zamówienia widać, ile odejmuje (link z kodem wpisuje go automatycznie). Kupon może obniżać tylko pierwszą płatność albo także każde odnowienie i nigdy nie obniża opłaty instalacyjnej.

To, co jest **w promocji**, ma odznakę Promocja i przekreśloną zwykłą cenę. Cena promocyjna dotyczy pierwszego okresu tego, co zamówią Państwo w czasie promocji; odnowienia są w zwykłej cenie. Kupon może nie obniżać dalej ceny promocyjnej.

<!-- if:terms -->
Przed złożeniem zamówienia prosimy przeczytać nasz Regulamin (link obok pola) i zaznaczyć **Akceptuję Regulamin**.
<!-- endif -->

Po złożeniu zamówienia wysyłamy e-mailem jego wezwanie do zapłaty. Uruchamiamy usługi, gdy tylko zamówienie jest gotowe, i wtedy sprawdzamy i rejestrujemy domenę.
<!-- endif -->

## Dane

Imię i nazwisko, firma, dane VAT i adres pojawiają się na Państwa wezwaniach do zapłaty i jako właściciel Państwa domen. O ich zmianę można poprosić w profilu; sprawdzamy zmianę i ją zatwierdzamy. E-mail i telefon zmieniają się od razu, podobnie jak dodatkowe dane, o które możemy prosić w sekcji Więcej szczegółów.

Nasze aktualności i oferty kończą się linkiem do ich wyłączenia; e-maile o usługach, wezwaniach do zapłaty i przypomnienia nadal przychodzą.

<!-- if:knowledge_base -->
## Baza wiedzy

W menu Wsparcie → Baza wiedzy znajdują się odpowiedzi na częste pytania, pogrupowane tematycznie. Aby znaleźć artykuł, wystarczy wpisać kilka słów w wyszukiwarce; znajduje je z polskimi znakami lub bez nich. Na końcu każdego artykułu prosimy dać znać, czy był pomocny.
<!-- if:tickets -->
Podczas wpisywania tematu nowego zgłoszenia pod nim pojawiają się artykuły, które mogą już odpowiadać na pytanie.
<!-- endif -->

<!-- endif -->
<!-- if:tickets -->
## Zgłoszenia

W menu Wsparcie → Zgłoszenia przycisk **Otwórz zgłoszenie** pozwala zadać nam dowolne pytanie: prosimy wybrać dział i usługę, której dotyczy, wpisać pytanie i załączyć pliki, np. zrzuty ekranu. Odpowiadamy w zgłoszeniu i e-mailem; aby odpisać, prosimy kliknąć w nim **Odpowiedz**. Prosimy zamknąć zgłoszenie, gdy nic więcej nie jest potrzebne. Zamknięte zgłoszenie otwiera się ponownie po odpowiedzi, a zgłoszenie, na które odpowiedzieliśmy, zamyka się samo, jeśli przez kilka dni nie otrzymamy odpowiedzi.
<!-- endif -->
<!-- if:ticket_mail -->
Na e-maile dotyczące zgłoszeń można też odpowiadać z własnej skrzynki: odpowiedź zostaje dodana do zgłoszenia wraz z załącznikami. E-mail wysłany na nasz adres wsparcia otwiera nowe zgłoszenie.
<!-- endif -->

<!-- if:announcements -->
## Ogłoszenia

W menu Wsparcie → Ogłoszenia publikujemy aktualności o naszych usługach: nowe usługi, zmiany cen i planowane prace konserwacyjne, od najnowszych.
<!-- endif -->
<!-- if:server_status -->
## Status serwerów

Wsparcie → Status serwerów pokazuje, czy nasze serwery teraz działają, z kwadratami ostatnich 30 dni i 24 godzin. Gdy któryś ma problem, jest na górze wraz z tym, co o nim wiemy.
<!-- endif -->
<!-- if:contact_page -->
## Kontakt

Wsparcie → Kontakt zawiera nasz adres, telefon, e-mail i godziny otwarcia, tak jak je uzupełniliśmy. Kliknięcie numeru telefonu lub adresu e-mail pozwala zadzwonić lub napisać, a **Otwórz w mapach** pomaga nas znaleźć.
<!-- endif -->

## Pytania

Prosimy pisać na {company_email}.
