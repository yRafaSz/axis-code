# Axis Code AI no Render

Esta versão usa Docker para executar PHP 8.3, cURL, Java 17 e Maven. As chaves ficam no painel do Render, nunca no repositório ou no navegador.

## Publicar no Render

1. Crie um repositório privado no GitHub e envie todos os arquivos deste ZIP, **exceto** `providers.local.php`.
2. No Render, escolha **New > Web Service**, conecte o repositório e selecione o runtime **Docker**.
3. Em **Environment**, crie uma variável secreta chamada `AXIS_AGENTS_JSON`.
4. Cole o JSON abaixo em uma única linha, substituindo apenas as chaves:

```json
{"agents":[{"modelLink":"cohere/north-mini-code:free","apiKey":"sk-or-v1-SUA_CHAVE_1"},{"modelLink":"nvidia/nemotron-3-super-120b-a12b:free","apiKey":"sk-or-v1-SUA_CHAVE_2"},{"modelLink":"google/gemma-4-31b-it:free","apiKey":"sk-or-v1-SUA_CHAVE_3"}]}
```

5. Defina o Health Check Path como `/api.php` e faça o deploy.
6. Abra a URL `onrender.com` criada pelo Render. Para conferir a configuração sem revelar chaves, abra `/diagnostico.php`.

## Segurança

- Nunca envie `providers.local.php` ao GitHub ou ao Render. O `.dockerignore` já impede que ele entre na imagem Docker.
- Revogue qualquer chave OpenRouter que tenha aparecido em captura de tela ou arquivo público.
- Para adicionar ou remover IA no Render, edite apenas `AXIS_AGENTS_JSON` e faça novo deploy.

## Maven

O container possui Java 17 e Maven, permitindo implementar um executor isolado em uma próxima etapa. O endpoint atual continua bloqueando execução remota por segurança: não exponha um comando Maven arbitrário em um site público.

## Desenvolvimento local

Para testar sem Docker, copie `providers.local.example.php` para `providers.local.php` e preencha suas chaves. Esse arquivo é apenas local e não deve ser enviado ao GitHub.
