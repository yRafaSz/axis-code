# Axis Code no Render

Esta versão executa PHP 8.3 com cURL e PDO MySQL. Possui autenticação por e-mail/senha, reCAPTCHA v2, perfil, quatro temas e OpenRouter configurado somente por variáveis secretas.

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

## 3. Configurar o TiDB Cloud no Render

Crie o banco `axis_code` no SQL Editor do TiDB Cloud:

```sql
CREATE DATABASE IF NOT EXISTS axis_code;
```

No painel do cluster, abra **Connect > Public Endpoint** e copie os dados exatos. Depois, no serviço web Axis do Render, abra **Environment** e adicione:

```text
DB_HOST=HOST_EXIBIDO_PELO_TIDB
DB_PORT=4000
DB_NAME=axis_code
DB_USER=USUARIO_EXATO_EXIBIDO_PELO_TIDB
DB_PASSWORD=SENHA_GERADA_PELO_TIDB
```

`DB_SSL_CA` é opcional. O Axis detecta automaticamente o certificado confiável instalado no Linux do Render. Somente configure essa variável se o TiDB fornecer um certificado CA próprio. Nesse caso, adicione o certificado como Secret File `tidb-ca.pem` no Render e use:

```text
DB_SSL_CA=/etc/secrets/tidb-ca.pem
```

Em **Connect > Outbound** no Render, copie todas as faixas de IP e permita essas faixas na lista de acesso do TiDB Cloud. O arquivo `auth.php` cria automaticamente a tabela `axis_users` na primeira conexão.

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

## 5. Segurança e perfil

- Senhas são armazenadas com `password_hash`, nunca em texto puro.
- O e-mail é imutável pela página de perfil.
- Avatares JPG, PNG, WEBP ou GIF de até 2 MB são armazenados no MySQL.
- As rotas de IA exigem uma sessão autenticada.
- Chaves OpenRouter, senha do TiDB e segredo reCAPTCHA ficam somente no Render.
- Revogue qualquer chave que já tenha aparecido em capturas ou commits públicos.

## Desenvolvimento local

Para rodar localmente, configure as mesmas variáveis `DB_*` e reCAPTCHA no ambiente do PHP. Para OpenRouter, você pode copiar `providers.local.example.php` para `providers.local.php`; nunca envie esse arquivo com chaves reais ao GitHub.
