## Como entrar

Entre com seu endereço de e-mail e sua senha. Se esquecer a senha, clique em "Esqueceu a senha?" na página de login e enviaremos por e-mail um link para escolher uma nova. O link funciona uma vez.

<!-- if:google_login -->
Se o seu endereço de e-mail for uma conta do Google, você também pode clicar em **Continuar com o Google** na página de login em vez de digitar a senha.
<!-- endif -->

Seu perfil (o menu com o seu nome, no fim do menu ou no canto superior direito) altera seu endereço de e-mail, telefone, idioma e senha, e pode ativar a autenticação em dois fatores para mais segurança.

<!-- if:notifications -->
O **sino** no topo da página mostra o que aconteceu desde a última vez que você olhou: um novo aviso de pagamento, um lembrete de pagamento ou aviso de atraso, um pagamento que recebemos, um serviço pronto, suspenso ou encerrado, um domínio prestes a renovar, um novo preço, nossa resposta ao seu ticket ou a uma solicitação. Um número nele mostra quantas você ainda não leu; clique em uma para abrir a página dela. Para não recebê-las, desative **Notificações** no seu perfil. Continuamos enviando e-mails como antes.

<!-- endif -->
<!-- if:email_history -->
**Histórico de e-mails**, no mesmo menu, lista os e-mails que enviamos a você, dos mais recentes aos mais antigos: clique em um para lê-lo de novo. E-mails com uma senha ou um link de acesso são listados sem o texto; use "Esqueceu a senha?" na página de login.

<!-- endif -->
**Dispositivos e acessos**, no mesmo menu, lista os navegadores conectados à sua conta e seus acessos recentes, incluindo senhas erradas. Clique em **Sair** ao lado de um dispositivo que você não usa mais, ou em **Desconectar outros dispositivos** para sair de todos os lugares, menos daqui. Se vir um acesso que não reconhece, altere também sua senha.

<!-- if:cookies -->
"Configurações de cookies", no rodapé de todas as páginas, mostra de novo a escolha de cookies, para que você possa alterá-la.
<!-- endif -->

<!-- if:welcome_tour -->
No primeiro acesso, um breve tour mostra o que você pode fazer aqui. O menu com o seu nome → **Tour** o mostra de novo.
<!-- endif -->

## Início

O Início mostra o que você tem a pagar, quantos serviços estão ativos e o que renova em seguida, com um link para cada item. Nossos comunicados, como trabalhos planejados no seu servidor, aparecem no topo do Início enquanto valem.

## Serviços e domínios

**Serviços** lista sua hospedagem e outros serviços com status, preço e próximo vencimento. O menu os divide em **Hospedagem** e **Outros serviços**. A página de uma conta de hospedagem mostra o ciclo de cobrança e tem um botão **Abrir site**. **Domínios** lista seus domínios com a data de expiração, os nameservers e se renovam automaticamente. Quando você tem serviços em mais de um status, os botões acima de cada lista mostram só os de um status (Ativo, Suspenso, …), com a quantidade. Um trabalho feito uma vez, como mudar o seu site, é cobrado uma vez e aparece como **Concluído** depois que você o paga.

<!-- if:hosting -->
Em uma conta de hospedagem ativa, você pode:

<!-- if:hosting_login -->
- **Entrar no painel de controle** (cPanel ou Plesk) sem digitar a senha dele: o botão o abre em uma nova aba.
- Usar os **Atalhos rápidos** (no cPanel e no Plesk) para abrir diretamente uma página do painel de controle, como contas de e-mail, o gerenciador de arquivos ou bancos de dados, já conectado.
<!-- endif -->
<!-- if:hosting_usage -->
- **Ver quanto espaço em disco e tráfego ela usa** este mês, em relação ao que o seu pacote permite.
<!-- endif -->
<!-- if:hosting_uptime -->
- **Ver a disponibilidade do servidor**: quanto dos últimos 30 dias e das últimas 24 horas o servidor em que a sua conta está ficou no ar. Na lista Hospedagem, um ponto antes do domínio mostra o estado atual do servidor: verde enquanto funciona, vermelho enquanto não responde, laranja durante uma manutenção. Passe o mouse sobre ele para ver a disponibilidade dos últimos 30 dias.
<!-- endif -->
<!-- if:vps_power -->
- **Iniciar, desligar ou reiniciar uma VPS** com os botões na página dela. Desligar e Reiniciar param os programas que rodam nela, então salve seu trabalho antes. Para usar o console, clique em **Entrar no Proxmox VE** e entre com o nome de usuário e a senha do serviço (realm "Proxmox VE authentication server"): você vê apenas a sua própria VPS.
<!-- endif -->
<!-- if:hosting_password -->
- **Alterar a senha** da conta. Use pelo menos 10 caracteres, com letras maiúsculas, minúsculas e números.
<!-- endif -->
<!-- endif -->

Para mover um serviço para um pacote maior ou menor, clique em **Upgrade / downgrade** na página dele e escolha o novo; cada um mostra o preço para o seu ciclo de cobrança. Depois que aprovarmos, o novo pacote vale na hora e o novo preço a partir do seu próximo vencimento (também podemos enviar um aviso de pagamento pelo restante do período atual). Confirmamos por e-mail; até lá, a página mostra a solicitação e **Retirar solicitação de mudança de pacote** a cancela.

<!-- if:orders -->
Quando há complementos disponíveis para um serviço (como backups diários), clique em **Adicionar um complemento** na página dele e escolha um; cada um mostra o preço para o seu ciclo de cobrança e quanto você paga agora. Agora você paga só pelos dias até o próximo vencimento do serviço (mais uma eventual taxa de configuração) e, a partir daí, o complemento renova junto com o serviço, no mesmo aviso de pagamento. O aviso de pagamento abre na hora; o complemento fica ativo depois de pago, e a página o lista em **Complementos** (com um link para o aviso enquanto ele aguarda).

<!-- endif -->
Para encerrar uma conta de hospedagem ou outro serviço, clique em **Solicitar cancelamento** na página dele. Diga o motivo se quiser e escolha se ele termina **no fim do período de cobrança** (você continua usando até o próximo vencimento) ou **imediatamente** (a conta e os arquivos dela são apagados). Se você tiver um domínio com o mesmo nome, também pode nos pedir para não renová-lo. Confirmamos por e-mail depois de realizar o cancelamento; até lá, a página mostra a solicitação e **Retirar solicitação de cancelamento** a cancela. Para um domínio, desative a renovação automática.

Quando mudamos o preço de um serviço, avisamos você por e-mail com antecedência, e a página dele mostra o novo preço em **Preço** com a data a partir da qual vale (por exemplo *A partir de 01/12/2026: €66.00*). As renovações com vencimento antes dessa data mantêm o preço atual, e os avisos de pagamento já emitidos não mudam.

<!-- if:incident_notice -->
Se um servidor em que está a sua hospedagem tiver um problema ou estiver em manutenção, um aviso no topo de todas as páginas informa isso e indica os serviços afetados. Ele some sozinho quando tudo voltar a funcionar, então não é preciso entrar em contato conosco sobre isso.

<!-- endif -->
<!-- if:ssl -->
Um **Certificado SSL** é pedido para o domínio que você informa ao fazer o pedido, depois que ele é pago. A página dele mostra o que fazer para provar que o domínio é seu (a autoridade certificadora verifica isso antes de emitir qualquer coisa), e também enviamos isso a você por e-mail:

- **Registro DNS (CNAME):** adicione o registro exibido (nome, tipo e valor) à zona DNS do domínio.
- **Arquivo no site:** crie o arquivo exibido, exatamente com o conteúdo dele, para que ele abra no endereço exibido.
- **E-mail para o administrador do domínio:** abra o link no e-mail enviado para admin@ (ou para o endereço escolhido) e aprove.

**Alterar validação** escolhe outra forma (ou outro endereço de aprovação) e **Verificar novamente** pede à autoridade certificadora que verifique agora (**Enviar o e-mail de aprovação de novo** quando for por e-mail). Quando o certificado é emitido, avisamos você por e-mail, e **Baixar certificado** fornece um zip com o certificado, o CA bundle e, quando nós a geramos, a chave privada, pronto para instalar no servidor. Um certificado dura no máximo 200 dias, então, enquanto o seu serviço estiver ativo, emitimos o próximo antes de cada um terminar: você valida o domínio de novo a cada vez e baixa o novo certificado.

<!-- endif -->
<!-- if:domains -->
Em um domínio ativo registrado conosco, você pode:

<!-- if:domain_nameservers -->
- **Alterar os nameservers** (de 2 a 4). A alteração vai para o registro na hora; a internet a vê em algumas horas.
<!-- endif -->
<!-- if:domain_epp -->
- **Obter o código EPP** de que você precisa para mover o domínio para outro provedor. Para alguns domínios, ele aparece na tela; para outros, é enviado ao endereço de e-mail do titular do domínio.
<!-- endif -->
<!-- if:domain_auto_renew -->
- **Desativar ou ativar a renovação automática.** Com ela desativada, você não recebe mais avisos de pagamento para o domínio e ele termina no vencimento.
<!-- endif -->
<!-- if:domain_contacts -->
- **Alterar os dados de contato** (titular, administrativo, técnico e, para alguns domínios, cobrança) como o registro os tem. **Usar os dados da minha conta** preenche um contato a partir do seu perfil e **Igual ao titular** copia os do titular. Alguns registros (como o .gr) não permitem alterar o nome do titular desta forma: fale conosco para isso.
<!-- endif -->
<!-- if:domain_child_nameservers -->
- **Registrar nameservers privados** com o nome do seu domínio (como ns1.seudominio.gr) com o endereço IP deles, alterar o IP ou apagá-los.
<!-- endif -->
<!-- if:domain_lock -->
- **Bloquear ou desbloquear o domínio** (bloqueio no registrador). Um domínio bloqueado não pode ser movido para outro provedor; desbloqueie-o só pouco antes de uma transferência. Nem todo registro tem bloqueio.
<!-- endif -->
<!-- endif -->

## Avisos de pagamento e pagamentos

Quando um serviço vence, enviamos a você por e-mail um **aviso de pagamento**, com as instruções para pagar. Ele é um aviso de pagamento, não um documento fiscal. **Avisos de pagamento** lista todos eles: abra um para ver as linhas, imprimi-lo ou salvá-lo como PDF. Os botões acima da lista mostram só os não pagos, pagos ou em atraso. **Pagamentos** lista os pagamentos que registramos. Depois que você paga, o aviso aparece como pago assim que registramos o seu pagamento.

<!-- if:invoices -->
Quando emitimos faturas, um aviso de pagamento pago também recebe uma **fatura**: enviamos por email em PDF, o aviso pago tem um botão para baixá-la, e **Faturas** (em Cobrança) lista todas.
<!-- endif -->

<!-- if:pay_online -->
Para pagar com cartão, abra um aviso de pagamento não pago e clique em **Pagar com cartão**. Você paga na página de pagamento segura do Stripe (nunca vemos os dados do seu cartão), e o aviso aparece como pago assim que o pagamento é aprovado.
<!-- endif -->

<!-- if:credit -->
## Orçamentos

Quando enviamos um orçamento, você recebe um e-mail com um link para ele, e **Orçamentos** (em Cobrança) lista os que enviamos a você, com o primeiro pagamento e por quanto tempo são válidos. Clique em um para abri-lo: você pode imprimi-lo lá. **Aceitar** faz o pedido pelos preços do orçamento (com os nossos Termos de Serviço, quando pedimos que você os aceite), e o aviso de pagamento é enviado a você por e-mail com as instruções para pagar. **Recusar** nos informa que você não o quer; dizer o motivo nos ajuda. Depois que a data dele passa, um orçamento não pode ser aceito: responda ao nosso e-mail e enviaremos um novo.

## Crédito

**Crédito** mostra o seu saldo de crédito e o histórico dele. Para adicionar fundos, clique em **Adicionar fundos** e informe um valor: criamos um aviso de pagamento para ele, que você paga como qualquer outro. Depois de pago, o valor é adicionado ao seu crédito. Você pode adicionar fundos quando tiver um serviço ativo.

O crédito paga seus avisos de pagamento uma linha por vez: uma linha (a renovação de um serviço) só é paga quando o seu crédito a cobre por inteiro, e o que ele não cobre continua no aviso para ser pago normalmente.
<!-- if:credit_auto -->
Usamos o seu crédito automaticamente em novos avisos de pagamento, e nos não pagos quando você adiciona fundos.
<!-- endif -->
<!-- if:credit_manual -->
Para usá-lo, abra um aviso de pagamento não pago e clique em **Pagar com crédito**.
<!-- endif -->
<!-- endif -->

<!-- if:orders -->
## Como pedir

**Pesquisa de domínios** (no menu, depois de Domínios) encontra um nome para você: digite-o (minhaloja ou minhaloja.gr) e você o vê com cada terminação que registramos, se está disponível ou indisponível, e o preço. **Adicionar ao carrinho** coloca um disponível no carrinho (**Com SSL** também adiciona um certificado para ele, quando oferecemos); para um que é seu, mas está em outro lugar, **É meu: transferir** abre o pedido de transferência. Talvez você precise antes confirmar que não é um robô. “Provavelmente disponível” significa que não foi possível consultar o registro naquele momento; verificamos de novo ao registrá-lo. Quando oferecemos, **Sugerir outros nomes** abaixo dos resultados lista nomes disponíveis próximos ao seu, sugeridos por IA, com a mesma terminação.

<!-- if:domain_prices -->
**Veja os preços de todas as terminações de domínio**, abaixo da caixa de pesquisa, lista cada terminação que vendemos com o preço para registrar, transferir e renovar.
<!-- endif -->

**Pedido** mostra o que você pode pedir; **Pedir um novo serviço**, acima dos seus Serviços, também o abre (**Pedir um domínio**, acima dos seus Domínios, abre a pesquisa de domínios). Quando vendemos vários tipos de serviço, escolha primeiro o tipo (como hospedagem compartilhada ou uma VPS, ou nomes de domínio) e depois um pacote: **Comparar pacotes**, abaixo deles, mostra lado a lado o que cada um inclui, com o preço para cada período de cobrança, e os botões **Escolher** dela também escolhem um pacote. Escolha um serviço e o período de cobrança, e as opções que ele oferecer (como uma localização ou endereços IP extras); cada opção mostra quanto acrescenta ao preço. Os complementos dele (como backups diários) podem ser marcados abaixo; eles ficam no mesmo aviso de pagamento e renovam junto com ele. Para hospedagem, diga se você já tem o domínio, se quer registrar um novo ou mover um que tem em outro lugar (você precisa do código EPP dele). Você também pode pedir só um domínio, com um certificado SSL para ele quando oferecemos (marque-o abaixo do domínio). Assim que você digita um domínio para registrar ou transferir, vê ao lado se ele está disponível; um domínio indisponível só pode ser transferido, e um ainda não registrado só pode ser registrado.

Clique em **Adicionar ao carrinho** e o **Carrinho** abre (o ícone do carrinho no topo de todas as páginas também o abre, com a quantidade de itens). Clique em **Pedir outra coisa** para adicionar mais, como dois domínios e três pacotes de hospedagem, ou na lixeira ao lado de um item para retirá-lo. Tudo o que está no carrinho vira um único pedido, com um único aviso de pagamento.

<!-- if:terms -->
Antes de fazê-lo, leia os nossos Termos de Serviço (o link ao lado da caixa) e marque **Aceito os Termos de Serviço**.
<!-- endif -->

Quando você faz o pedido, enviamos por e-mail o aviso de pagamento dele. Configuramos os serviços assim que o pedido estiver pronto, e nesse momento verificamos e registramos o domínio.
<!-- endif -->

## Seus dados

Seu nome, empresa, dados de IVA e endereço aparecem nos seus avisos de pagamento e como titular dos seus domínios. Você pode pedir para alterá-los pelo seu perfil; analisamos a alteração e a aprovamos. O e-mail e o telefone mudam na hora, assim como os dados extras que podemos pedir em Mais detalhes.

Nossas novidades e ofertas terminam com um link para não recebê-las mais; os e-mails sobre seus serviços, avisos de pagamento e lembretes continuam chegando.

<!-- if:knowledge_base -->
## Base de conhecimento

Em Suporte → Base de conhecimento você encontra respostas para perguntas comuns, agrupadas por assunto. Digite algumas palavras na pesquisa para encontrar um artigo; ela os encontra com ou sem acentos. No fim de cada artigo, diga se ele ajudou.
<!-- if:tickets -->
Enquanto você escreve o assunto de um novo ticket, os artigos que talvez já o respondam aparecem abaixo dele.
<!-- endif -->

<!-- endif -->
<!-- if:tickets -->
## Tickets de suporte

Em Suporte → Tickets, use **Abrir um ticket** para nos perguntar qualquer coisa: escolha o departamento e o serviço a que se refere, escreva sua pergunta e anexe arquivos, como capturas de tela. Respondemos no ticket e por e-mail; clique em **Responder** nele para escrever de volta. Feche-o quando não precisar de mais nada. Um ticket fechado é reaberto se você responder, e um que respondemos fecha sozinho quando não temos notícias suas por alguns dias.
<!-- endif -->
<!-- if:ticket_mail -->
Você também pode responder aos nossos e-mails de tickets pela sua própria caixa de e-mail: sua resposta é adicionada ao ticket, com os arquivos anexados. Um e-mail que você envia para o nosso endereço de suporte abre um novo ticket.
<!-- endif -->

<!-- if:announcements -->
## Comunicados

Em Suporte → Comunicados publicamos novidades sobre os nossos serviços: novos serviços, mudanças de preço e manutenções planejadas, dos mais recentes aos mais antigos.
<!-- endif -->
<!-- if:server_status -->
## Status dos servidores

Suporte → Status dos servidores mostra se os nossos servidores estão funcionando agora, com os quadrados dos últimos 30 dias e das últimas 24 horas. Quando um tem um problema, ele aparece no topo com o que sabemos a respeito.
<!-- endif -->
<!-- if:contact_page -->
## Fale conosco

Suporte → Fale conosco tem o nosso endereço, telefone, e-mail e horário de atendimento, como os preenchemos. Clique em um número de telefone ou endereço de e-mail para ligar ou escrever, e em **Abrir no mapa** para nos encontrar.
<!-- endif -->

## Dúvidas

Escreva para nós em {company_email}.
