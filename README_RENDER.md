# Axis Code AI no Render

Esta versão usa Docker para executar PHP 8.3 e cURL. As chaves ficam no painel do Render, nunca no repositório ou no navegador.

## Publicar no Render

1. Crie um repositório privado no GitHub e envie todos os arquivos deste ZIP. Não crie nem envie `providers.local.php` com chaves reais; o `.gitignore` impede commits acidentais feitos pelo Git, mas não substitui sua revisão no site do GitHub.
2. No Render, escolha **New > Web Service**, conecte o repositório e selecione o runtime **Docker**.
3. Em **Environment**, crie uma variável secreta chamada `AXIS_AGENTS_JSON`.
4. Cole o JSON abaixo em uma única linha, substituindo apenas as chaves:

```json
{"agents":[{"modelLink":"nvidia/nemotron-3-ultra-550b-a55b:free","apiKey":"sk-or-v1-SUA_CHAVE_1"},{"modelLink":"nvidia/nemotron-3-super-120b-a12b:free","apiKey":"sk-or-v1-SUA_CHAVE_2"},{"modelLink":"cohere/north-mini-code:free","apiKey":"sk-or-v1-SUA_CHAVE_3"},{"modelLink":"google/gemma-4-31b-it:free","apiKey":"sk-or-v1-SUA_CHAVE_4"},{"modelLink":"google/gemma-4-26b-a4b-it:free","apiKey":"sk-or-v1-SUA_CHAVE_5"}]}
```

5. Defina o Health Check Path como `/api.php` e faça o deploy.
6. Abra a URL `onrender.com` criada pelo Render. Para conferir a configuração sem revelar chaves, abra `/diagnostico.php`.

## Segurança

- Nunca envie `providers.local.php` ao GitHub ou ao Render. O `.dockerignore` já impede que ele entre na imagem Docker.
- Revogue qualquer chave OpenRouter que tenha aparecido em captura de tela ou arquivo público.
- Para adicionar ou remover IA no Render, edite apenas `AXIS_AGENTS_JSON` e faça novo deploy.

## Maven

O site mantém a autorização visual do Maven, mas a execução remota permanece bloqueada por segurança. Para habilitá-la no Render, o correto é criar um worker isolado com Java/Maven e uma área temporária por tarefa; não exponha comandos Maven arbitrários no mesmo servidor público do chat.

## Desenvolvimento local

Para testar sem Docker, copie `providers.local.example.php` para `providers.local.php` e preencha suas chaves. Esse arquivo é apenas local e não deve ser enviado ao GitHub.
