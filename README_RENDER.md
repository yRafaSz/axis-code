# Axis Code no Render

Esta versão executa PHP 8.3 com cURL e PDO MySQL. Possui autenticação por e-mail/senha, reCAPTCHA v2, login Google, perfil, quatro temas e OpenRouter configurado somente por variáveis secretas.

## 1. Atualizar o serviço web

1. Envie os arquivos deste ZIP ao repositório privado do GitHub.
2. Não envie `providers.local.php`, `.env` ou qualquer chave real.
3. No Render, mantenha o serviço como **Docker** e o Health Check Path como `/api.php`.
4. O novo Dockerfile instala `pdo_mysql`; portanto escolha **Save, rebuild, and deploy** no próximo deploy.

## 2. OpenRouter

No serviço web, abra **Environment** e mantenha `AXIS_AGENTS_JSON` em uma única linha:

```json
{"agents":[{"modelLink":"nvidia/nemotron-3-ultra-550b-a55b:free","apiKey":"sk-or-v1-SUA_CHAVE_1"},{"modelLink":"nvidia/nemotron-3-super-120b-a12b:free","apiKey":"sk-or-v1-SUA_CHAVE_2"},{"modelLink":"cohere/north-mini-code:free","apiKey":"sk-or-v1-SUA_CHAVE_3"},{"modelLink":"google/gemma-4-31b-it:free","apiKey":"sk-or-v1-SUA_CHAVE_4"},{"modelLink":"google/gemma-4-26b-a4b-it:free","apiKey":"sk-or-v1-SUA_CHAVE_5"}]}
```

O Axis usa `openrouter/free` como última alternativa quando modelos gratuitos específicos recebem 429. Isso melhora disponibilidade, mas não remove a cota da conta OpenRouter. Várias chaves da mesma conta compartilham o limite dessa conta.

## 3. Criar o MySQL no Render

O Render disponibiliza MySQL como um **Private Service** com Docker e disco persistente.

1. Use o template oficial `render-examples/mysql` ou siga `https://render.com/docs/deploy-mysql`.
2. Crie o MySQL na mesma região e workspace do Axis.
3. Configure no serviço MySQL:

```text
MYSQL_DATABASE=axis_code
MYSQL_USER=axis
MYSQL_PASSWORD=UMA_SENHA_FORTE
MYSQL_ROOT_PASSWORD=OUTRA_SENHA_FORTE
```

4. Adicione um disco persistente montado exatamente em `/var/lib/mysql`.
5. Copie o hostname interno exibido pelo MySQL. Ele costuma ser semelhante a `mysql-xxxxx`.
6. No serviço web Axis, adicione:

```text
DB_HOST=mysql-xxxxx
DB_PORT=3306
DB_NAME=axis_code
DB_USER=axis
DB_PASSWORD=A_MESMA_SENHA_DE_MYSQL_PASSWORD
```

O arquivo `auth.php` cria automaticamente a tabela `axis_users` na primeira conexão. Não é necessário importar SQL manualmente.

## 4. Configurar reCAPTCHA v2

1. Acesse `https://www.google.com/recaptcha/admin/create`.
2. Selecione **Challenge (v2)** e **I'm not a robot Checkbox**.
3. Cadastre `axis-code.onrender.com` e seu domínio personalizado, se houver. Informe apenas o domínio, sem `https://` e sem caminhos.
4. Copie a chave do site e a chave secreta.
5. No **Environment** do serviço Axis, adicione:

```text
RECAPTCHA_SITE_KEY=SUA_CHAVE_DO_SITE
RECAPTCHA_SECRET_KEY=SUA_CHAVE_SECRETA
```

Se essas variáveis não forem informadas, login e cadastro ainda funcionam, mas o desafio antirobô fica desativado.

## 5. Configurar login com Google

1. Acesse `https://console.cloud.google.com/` e crie ou selecione um projeto.
2. Configure a tela de consentimento OAuth. Para testes, adicione sua conta em **Test users**.
3. Vá a **APIs & Services > Credentials > Create Credentials > OAuth client ID**.
4. Escolha **Web application**.
5. Em **Authorized JavaScript origins**, adicione:

```text
https://axis-code.onrender.com
```

6. Em **Authorized redirect URIs**, adicione exatamente:

```text
https://axis-code.onrender.com/auth.php?action=google-callback
```

7. No serviço Axis, adicione:

```text
GOOGLE_CLIENT_ID=SEU_CLIENT_ID.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=SEU_CLIENT_SECRET
GOOGLE_REDIRECT_URI=https://axis-code.onrender.com/auth.php?action=google-callback
```

Troque `axis-code.onrender.com` se o endereço real do serviço for diferente. A URI configurada no Google e a variável `GOOGLE_REDIRECT_URI` precisam ser idênticas.

## 6. Segurança e perfil

- Senhas são armazenadas com `password_hash`, nunca em texto puro.
- O e-mail é imutável pela página de perfil.
- Avatares JPG, PNG, WEBP ou GIF de até 2 MB são armazenados no MySQL.
- As rotas de IA exigem uma sessão autenticada.
- Chaves OpenRouter, segredo Google, senha MySQL e segredo reCAPTCHA ficam somente no Render.
- Revogue qualquer chave que já tenha aparecido em capturas ou commits públicos.

## Desenvolvimento local

Para rodar localmente, configure as mesmas variáveis `DB_*`, Google e reCAPTCHA no ambiente do PHP. Para OpenRouter, você pode copiar `providers.local.example.php` para `providers.local.php`; nunca envie esse arquivo com chaves reais ao GitHub.
