## Giriş yapma

E-posta adresiniz ve parolanızla giriş yapın. Parolanızı unutursanız giriş sayfasında "Şifrenizi mi unuttunuz?" seçeneğine basın; size yeni bir parola seçmeniz için bir bağlantı e-postayla gönderelim. Bağlantı bir kez çalışır.

<!-- if:google_login -->
E-posta adresiniz bir Google hesabıysa, parolanızı yazmak yerine giriş sayfasında **Google ile devam et**'e de basabilirsiniz.
<!-- endif -->

Profiliniz (menünün altında veya sağ üstte adınızın bulunduğu menü) e-posta adresinizi, telefon numaranızı, dilinizi ve parolanızı değiştirir ve ek güvenlik için iki adımlı doğrulamayı açabilir.

<!-- if:notifications -->
Sayfanın üstündeki **zil**, son baktığınızdan beri neler olduğunu söyler: yeni bir ödeme bildirimi, bir ödeme hatırlatması veya gecikme bildirimi, aldığımız bir ödeme, hazır olan, askıya alınan veya sona eren bir hizmet, yenilenmek üzere olan bir alan adı, yeni bir fiyat, destek talebinize veya bir isteğinize verdiğimiz yanıt. Üzerindeki sayı kaç tanesini okumadığınızı gösterir; sayfasını açmak için birine basın. Durdurmak için profilinizde **Bildirimler**'i kapatın. Size yine eskisi gibi e-posta göndeririz.

<!-- endif -->
<!-- if:email_history -->
Aynı menüdeki **E-posta geçmişi**, size gönderdiğimiz e-postaları en yeniden başlayarak listeler: yeniden okumak için birine basın. Parola veya giriş bağlantısı içeren e-postalar metinleri olmadan listelenir; bunun yerine giriş sayfasındaki "Şifrenizi mi unuttunuz?" seçeneğini kullanın.

<!-- endif -->
Aynı menüdeki **Cihazlar ve oturum açmalar**, hesabınızda oturumu açık olan tarayıcıları ve yanlış parolalar dahil son oturum açmalarınızı listeler. Artık kullanmadığınız bir cihazın yanındaki **Oturumu kapat**'a veya buradaki hariç her yerde oturumu kapatmak için **Diğer cihazlarda oturumu kapat**'a basın. Tanımadığınız bir oturum açma görürseniz parolanızı da değiştirin.

<!-- if:cookies -->
Her sayfanın altındaki "Çerez ayarları" çerez seçimini yeniden gösterir, böylece değiştirebilirsiniz.
<!-- endif -->

## Ana sayfa

Ana sayfa ne ödemeniz gerektiğini, kaç hizmetin etkin olduğunu ve sırada neyin yenileneceğini, her birine bir bağlantıyla gösterir. Sunucunuzdaki planlı çalışmalar gibi duyurularımız, geçerli oldukları sürece Ana sayfanın üstünde görünür.

## Hizmetler ve alan adları

**Hizmetler**, hosting ve diğer hizmetlerinizi durumları, fiyatları ve sonraki vade tarihleriyle listeler. Menü bunları **Hosting** ve **Diğer hizmetler** olarak ayırır. Bir hosting hesabının sayfası ödeme dönemini gösterir ve bir **Web sitesini aç** düğmesi içerir. **Alan adları**, alan adlarınızı bitiş tarihleri, ad sunucuları ve otomatik yenilenip yenilenmedikleriyle listeler. Birden fazla durumda hizmetiniz olduğunda, her listenin üstündeki düğmeler yalnızca tek bir durumdakileri (Etkin, Askıda, …) kaç tane olduklarıyla gösterir. Web sitenizin taşınması gibi bir kez yapılan işler bir kez ücretlendirilir ve ödedikten sonra **Tamamlandı** olarak görünür.

<!-- if:hosting -->
Etkin bir hosting hesabında şunları yapabilirsiniz:

<!-- if:hosting_login -->
- Parolasını yazmadan **Kontrol paneline giriş yap** (cPanel veya Plesk): düğme onu yeni bir sekmede açar.
- Kontrol panelinin e-posta hesapları, dosya yöneticisi veya veritabanları gibi bir sayfasını oturum açılmış olarak doğrudan açmak için **Hızlı kısayollar**'ı (cPanel ve Plesk'te) kullanın.
<!-- endif -->
<!-- if:hosting_usage -->
- Bu ay **ne kadar disk alanı ve trafik kullandığını** paketinizin izin verdiğiyle karşılaştırarak görün.
<!-- endif -->
<!-- if:hosting_uptime -->
- **Sunucunun çalışma süresini görün**: hesabınızın bulunduğu sunucunun son 30 gün ve 24 saatin ne kadarında çalıştığını. Hosting listesinde alan adının önündeki nokta sunucunun şu anki durumunu gösterir: çalışırken yeşil, yanıt vermezken kırmızı, bakım sırasında turuncu. Son 30 günün çalışma süresini görmek için üzerine gelin.
<!-- endif -->
<!-- if:vps_power -->
- Sayfasındaki düğmelerle **bir VPS'i başlatın, kapatın veya yeniden başlatın**. Kapat ve Yeniden başlat üzerinde çalışan programları durdurur, bu yüzden önce çalışmanızı kaydedin. Konsolunu kullanmak için **Proxmox VE paneline giriş yap**'a basın ve hizmetin kullanıcı adı ve parolasıyla giriş yapın (realm "Proxmox VE authentication server"): yalnızca kendi VPS'inizi görürsünüz.
<!-- endif -->
<!-- if:hosting_password -->
- Hesabın **parolasını değiştirin**. Büyük ve küçük harf ile rakam içeren en az 10 karakter kullanın.
<!-- endif -->
<!-- endif -->

Bir hizmeti daha büyük veya daha küçük bir pakete taşımak için sayfasındaki **Yükselt / düşür**'e basın ve yenisini seçin; her biri ödeme döneminiz için fiyatını gösterir. Onayladığımızda yeni paket hemen, yeni fiyat ise bir sonraki vade tarihinizden itibaren geçerli olur (mevcut dönemin kalanı için bir ödeme bildirimi de gönderebiliriz). E-postayla onaylarız; o zamana kadar sayfa isteği gösterir ve **Paket değişikliği isteğini geri çek** onu geri alır.

<!-- if:orders -->
Bir hizmet için ek hizmetler (günlük yedekler gibi) mevcut olduğunda, sayfasındaki **Bir ek hizmet ekle**'ye basın ve birini seçin; her biri ödeme döneminiz için fiyatını ve şimdi ne ödeyeceğinizi gösterir. Şimdi yalnızca hizmetin bir sonraki vade tarihine kadarki günler için ödersiniz (artı varsa kurulum ücreti); o tarihten itibaren ek hizmet, hizmetle birlikte aynı ödeme bildiriminde yenilenir. Ödeme bildirimi hemen açılır; ek hizmet ödendiğinde etkinleşir ve sayfa onu **Ek hizmetler** altında listeler (beklerken bildirime bir bağlantıyla).

<!-- endif -->
Bir hosting hesabını veya başka bir hizmeti durdurmak için sayfasındaki **İptal iste**'ye basın. İsterseniz nedenini yazın ve **ödeme döneminin sonunda** (bir sonraki vade tarihine kadar kullanmaya devam edersiniz) mı yoksa **hemen** mi (hesap ve dosyaları silinir) sona ereceğini seçin. Aynı adda bir alan adınız varsa onu yenilememizi de istemeyebilirsiniz. Gerçekleştirdiğimizde e-postayla onaylarız; o zamana kadar sayfa isteği gösterir ve **İptal isteğini geri çek** onu geri alır. Bir alan adı için bunun yerine otomatik yenilemeyi kapatın.

Bir hizmetin fiyatını değiştirdiğimizde size önceden e-posta göndeririz ve sayfası yeni fiyatı **Fiyat** altında geçerli olacağı tarihle gösterir (örneğin *01/12/2026 tarihinden itibaren: €66,00*). Bu tarihten önce vadesi gelen yenilemeler mevcut fiyatı korur ve zaten düzenlenmiş ödeme bildirimleri değişmez.

<!-- if:incident_notice -->
Hostinginizin bulunduğu bir sunucuda sorun varsa veya bakımdaysa, her sayfanın üstündeki bir bildirim bunu söyler ve etkilenen hizmetleri belirtir. Her şey yeniden çalıştığında kendiliğinden kaybolur, bu yüzden bu konuda bize ulaşmanıza gerek yoktur.

<!-- endif -->
<!-- if:ssl -->
Bir **SSL sertifikası**, sipariş verirken belirttiğiniz alan adı için ödendikten sonra sipariş edilir. Sayfası, alan adının size ait olduğunu kanıtlamak için ne yapmanız gerektiğini gösterir (sertifika otoritesi bir şey düzenlemeden önce bunu kontrol eder) ve bunu size e-postayla da göndeririz:

- **DNS kaydı (CNAME):** gösterilen kaydı (ad, tür ve değer) alan adının DNS bölgesine ekleyin.
- **Web sitesinde dosya:** gösterilen dosyayı tam olarak içeriğiyle oluşturun, böylece gösterilen adreste açılsın.
- **Alan adının yöneticisine e-posta:** admin@ (veya seçilen) adrese gönderilen e-postadaki bağlantıyı açın ve onaylayın.

**Doğrulamayı değiştir** başka bir yol (veya başka bir onay adresi) seçer ve **Yeniden kontrol et** sertifika otoritesinden şimdi bakmasını ister (e-postayla doğrulamada **Onay e-postasını yeniden gönder**). Sertifika düzenlendiğinde size e-posta göndeririz ve **Sertifikayı indir**, sunucuya kurulmaya hazır sertifikayı, CA bundle'ı ve onu biz oluşturduysak özel anahtarı içeren bir zip verir. Bir sertifika en fazla 200 gün geçerlidir, bu yüzden hizmetiniz sürdükçe her biri bitmeden sonrakini düzenleriz: her seferinde alan adını yeniden doğrular ve yeni sertifikayı indirirsiniz.

<!-- endif -->
<!-- if:domains -->
Bizim üzerimizden kayıtlı etkin bir alan adında şunları yapabilirsiniz:

<!-- if:domain_nameservers -->
- **Ad sunucularını değiştirin** (2 ila 4). Değişiklik hemen kayıt otoritesine gider; internet bunu birkaç saat içinde görür.
<!-- endif -->
<!-- if:domain_epp -->
- Alan adını başka bir sağlayıcıya taşımak için gereken **EPP kodunu alın**. Bazı alan adlarında ekranda gösterilir; diğerlerinde alan adı sahibinin e-posta adresine gönderilir.
<!-- endif -->
<!-- if:domain_auto_renew -->
- **Otomatik yenilemeyi kapatın veya açın.** Kapalıyken alan adı için artık ödeme bildirimi almazsınız ve vade tarihinde sona erer.
<!-- endif -->
<!-- if:domain_contacts -->
- Kayıt otoritesindeki haliyle **iletişim bilgilerini değiştirin** (sahip, idari, teknik ve bazı alan adlarında ödeme iletişim kişisi). **Hesap bilgilerimi kullan** bir kişiyi profilinizden doldurur, **Sahiple aynı** ise sahibinkini kopyalar. Bazı kayıt otoriteleri (.gr gibi) sahibin adının bu şekilde değiştirilmesine izin vermez: bunun için bize ulaşın.
<!-- endif -->
<!-- if:domain_child_nameservers -->
- Alan adınızı taşıyan **özel ad sunucularını** (ns1.alanadiniz.com.tr gibi) IP adresleriyle kaydedin, IP'lerini değiştirin veya silin.
<!-- endif -->
<!-- if:domain_lock -->
- **Alan adını kilitleyin veya kilidini açın** (kayıt kuruluşu kilidi). Kilitli bir alan adı başka bir sağlayıcıya taşınamaz; kilidi yalnızca transferden hemen önce açın. Her kayıt otoritesinde kilit yoktur.
<!-- endif -->
<!-- endif -->

## Ödeme bildirimleri ve ödemeler

Bir hizmetin vadesi geldiğinde size nasıl ödeyeceğinizi içeren bir **ödeme bildirimi** e-postayla göndeririz. Bu bir ödeme bildirimidir, vergi belgesi değildir. **Ödeme bildirimleri** hepsini listeler: satırlarını görmek, yazdırmak veya PDF olarak kaydetmek için birini açın. Listenin üstündeki düğmeler yalnızca ödenmemiş, ödenmiş veya vadesi geçmiş olanları gösterir. **Ödemeler** kaydettiğimiz ödemeleri listeler. Ödeme yaptıktan sonra, ödemenizi kaydettiğimizde bildirim ödendi olarak görünür.

<!-- if:pay_online -->
Kartla ödemek için ödenmemiş bir ödeme bildirimini açın ve **Kartla öde**'ye basın. Stripe'ın güvenli ödeme sayfasında ödersiniz (kart bilgilerinizi asla görmeyiz) ve ödeme gerçekleşir gerçekleşmez bildirim ödendi olarak görünür.
<!-- endif -->

<!-- if:credit -->
## Teklifler

Size bir teklif gönderdiğimizde ona bağlantı içeren bir e-posta alırsınız ve **Teklifler** (Ödeme işlemleri altında) size gönderdiklerimizi ilk ödemeleri ve geçerlilik süreleriyle listeler. Açmak için birine basın: orada yazdırabilirsiniz. **Kabul et** siparişi teklifin fiyatlarıyla verir (kabul etmenizi istediğimizde Hizmet Koşullarımızla birlikte) ve ödeme bildirimi nasıl ödeyeceğinizle birlikte size e-postayla gönderilir. **Reddet** bize istemediğinizi söyler; nedenini yazmanız bize yardımcı olur. Tarihi geçtikten sonra bir teklif kabul edilemez: e-postamızı yanıtlayın, size yenisini gönderelim.

## Bakiye

**Bakiye** bakiyenizi ve geçmişini gösterir. Bakiye yüklemek için **Bakiye yükle**'ye basın ve bir tutar girin: bunun için diğerleri gibi ödediğiniz bir ödeme bildirimi oluştururuz. Ödendiğinde tutar bakiyenize eklenir. Etkin bir hizmetiniz olduğunda bakiye yükleyebilirsiniz.

Bakiye, ödeme bildirimlerinizi satır satır öder: bir satır (bir hizmetin yenilemesi) yalnızca bakiyeniz tamamını karşıladığında ödenir ve karşılamadığı her şey bildirimde her zamanki gibi ödenmek üzere kalır.
<!-- if:credit_auto -->
Bakiyenizi yeni ödeme bildirimlerinde ve bakiye yüklediğinizde ödenmemiş olanlarda otomatik olarak kullanırız.
<!-- endif -->
<!-- if:credit_manual -->
Kullanmak için ödenmemiş bir ödeme bildirimini açın ve **Bakiyeyle öde**'ye basın.
<!-- endif -->
<!-- endif -->

<!-- if:orders -->
## Sipariş verme

**Alan adı arama** (menüde, Alan adlarından sonra) sizin için bir ad bulur: yazın (magazam veya magazam.com.tr) ve kaydettiğimiz her uzantıyla, müsait mi alınmış mı olduğunu ve fiyatını görün. **Sepete ekle** müsait olanı sepete koyar (sunduğumuzda **SSL ile** onun için bir sertifika da ekler); size ait olup başka yerde bulunan biri için **Benim: transfer et** transfer siparişini açar. Önce robot olmadığınızı doğrulamanız istenebilir. “Muhtemelen müsait”, kayıt otoritesine o anda sorulamadığı anlamına gelir; kaydederken yeniden kontrol ederiz. Sunduğumuzda, sonuçların altındaki **Başka adlar öner** sizinkine yakın, yapay zekânın önerdiği, aynı uzantılı müsait adları listeler.

<!-- if:domain_prices -->
Arama kutusunun altındaki **Her alan adı uzantısının fiyatlarını görün**, sattığımız her uzantıyı kayıt, transfer ve yenileme fiyatıyla listeler.
<!-- endif -->

**Sipariş** neler sipariş edebileceğinizi gösterir; Hizmetlerinizin üstündeki **Yeni bir hizmet sipariş et** de onu açar (Alan adlarınızın üstündeki **Alan adı sipariş et** alan adı aramayı açar). Birkaç tür hizmet sattığımızda önce türü (paylaşımlı hosting veya VPS ya da alan adları gibi), sonra bir paketi seçin: altlarındaki **Paketleri karşılaştır** her birinin neleri içerdiğini yan yana, her ödeme dönemi için fiyatıyla gösterir ve **Seç** düğmeleri de bir paket seçer. Bir hizmeti ve ödeme dönemini ve sunduğu seçenekleri (konum veya ek IP adresleri gibi) seçin; her seçenek fiyata ne eklediğini gösterir. Onun için ek hizmetler (günlük yedekler gibi) aşağıda işaretlenebilir; aynı ödeme bildirimindedirler ve onunla birlikte yenilenirler. Hosting için alan adına zaten sahip olup olmadığınızı, yeni bir tane kaydetmek mi yoksa başka yerdeki bir alan adınızı taşımak mı istediğinizi belirtin (EPP kodu gerekir). Ayrıca tek başına bir alan adı da sipariş edebilirsiniz; sunduğumuzda onun için bir SSL sertifikasıyla birlikte (alan adının altında işaretleyin). Kaydedilecek veya transfer edilecek bir alan adını yazdığınızda yanında müsait olup olmadığını görürsünüz; alınmış bir alan adı yalnızca transfer edilebilir, henüz kayıtlı olmayan ise yalnızca kaydedilebilir.

**Sepete ekle**'ye basın ve **Sepet** açılır (her sayfanın üstündeki sepet simgesi de içindeki ürün sayısıyla birlikte onu açar). İki alan adı ve üç hosting planı gibi daha fazlasını eklemek için **Başka bir şey sipariş et**'e, bir kalemi çıkarmak için yanındaki çöp kutusuna basın. Sepetteki her şey tek bir ödeme bildirimiyle tek bir sipariş olur.

<!-- if:terms -->
Siparişi vermeden önce Hizmet Koşullarımızı okuyun (kutunun yanındaki bağlantı) ve **Hizmet Koşullarını kabul ediyorum** kutusunu işaretleyin.
<!-- endif -->

Siparişi verdiğinizde size ödeme bildirimini e-postayla göndeririz. Sipariş hazır olur olmaz hizmetleri kurar, alan adını da o zaman kontrol edip kaydederiz.
<!-- endif -->

## Bilgileriniz

Adınız, şirketiniz, vergi bilgileriniz ve adresiniz ödeme bildirimlerinizde ve alan adlarınızın sahibi olarak görünür. Bunların değiştirilmesini profilinizden isteyebilirsiniz; değişikliği inceleyip onaylarız. E-posta ve telefon hemen değişir; Daha fazla bilgi altında isteyebileceğimiz ek bilgiler de öyle.

Haberlerimiz ve tekliflerimiz, onları durdurmak için bir bağlantıyla biter; hizmetleriniz, ödeme bildirimleri ve hatırlatmalarla ilgili e-postalar gelmeye devam eder.

<!-- if:knowledge_base -->
## Bilgi bankası

Destek → Bilgi bankası altında sık sorulan soruların yanıtlarını konuya göre gruplanmış olarak bulursunuz. Bir makale bulmak için aramaya birkaç kelime yazın; aksanlı veya aksansız bulur. Her makalenin sonunda işinize yarayıp yaramadığını bize söyleyin.
<!-- if:tickets -->
Yeni bir destek talebinin konusunu yazarken, ona zaten yanıt olabilecek makaleler altında görünür.
<!-- endif -->

<!-- endif -->
<!-- if:tickets -->
## Destek talepleri

Destek → Destek talepleri altında bize her şeyi sormak için **Destek talebi aç**: departmanı ve ilgili hizmeti seçin, sorunuzu yazın ve ekran görüntüleri gibi dosyalar ekleyin. Talebe hem buradan hem de e-postayla yanıt veririz; yanıt yazmak için talepteki **Yanıtla**'ya basın. Başka bir şeye ihtiyacınız kalmadığında kapatın. Kapalı bir talep yanıt verirseniz yeniden açılır; yanıtladığımız bir talep ise birkaç gün sizden haber alamazsak kendiliğinden kapanır.
<!-- endif -->
<!-- if:ticket_mail -->
Destek talebi e-postalarımızı kendi posta kutunuzdan da yanıtlayabilirsiniz: yanıtınız ekli dosyalarıyla birlikte talebe eklenir. Destek adresimize gönderdiğiniz bir e-posta yeni bir destek talebi açar.
<!-- endif -->

<!-- if:announcements -->
## Duyurular

Destek → Duyurular altında hizmetlerimizle ilgili haberleri paylaşırız: yeni hizmetler, fiyat değişiklikleri ve planlı bakımlar, en yeniden başlayarak.
<!-- endif -->
<!-- if:server_status -->
## Sunucu durumu

Destek → Sunucu durumu, sunucularımızın şu anda çalışıp çalışmadığını son 30 gün ve 24 saatin kareleriyle gösterir. Birinde sorun olduğunda, hakkında bildiklerimizle birlikte en üstte yer alır.
<!-- endif -->
<!-- if:contact_page -->
## Bize ulaşın

Destek → Bize ulaşın, doldurduğumuz şekliyle adresimizi, telefonumuzu, e-postamızı ve çalışma saatlerimizi içerir. Aramak veya yazmak için bir telefon numarasına veya e-posta adresine, bizi bulmak için **Haritada aç**'a basın.
<!-- endif -->

## Sorular

Bize {company_email} adresinden yazın.
