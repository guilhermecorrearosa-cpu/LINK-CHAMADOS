# 📋 Painel de Chamados com Defeito - Mil Cópias (Databit ERP)

Versão aprimorada do monitor de chamados `cham1.php`, desenvolvida especificamente para exibir o **defeito / motivo reclamado** de cada Ordem de Serviço (OS), com layout moderno, veloz e otimizado para **celular (mobile-first)** e **computador / TV**.

---

## 🚀 Recursos Principais

1. **Exibição do Defeito / Motivo:**
   * Cada OS exibe uma caixa destacada com o motivo do chamado ou defeito relatado pelo cliente.
2. **Foco Total no cham1 (Sem Triagem & Com Triagem):**
   * **Sem Triagem:** OS, Cliente, Local, Cidade, Origem, Data, SLA e Alerta de Atraso.
   * **Com Triagem:** OS, Cliente, Local, Cidade, Encaminhado, Técnico com cor oficial e Check-In.
3. **Design Mobile-First:**
   * No **celular**: navegação em abas limpas ("Sem Triagem" e "Com Triagem"), sem necessidade de rolagem horizontal.
   * No **computador / TV**: visualização em colunas lado a lado no padrão dashboard.
4. **Busca e Filtros Instantâneos:**
   * Campo de busca universal (filtra por número de OS, cliente, defeito, cidade ou técnico na hora).
   * Filtros rápidos: *Todos*, *Atrasados (SLA)*, *Linhares*, *Colatina*.
5. **Atualização Automática:**
   * Contador regressivo de 60 segundos com atualização em tempo real e botão de recarga manual imediata.
6. **Modo Escuro / Claro (Dark Mode):**
   * Alternância com um clique para economizar bateria no celular ou facilitar a leitura noturna.
7. **Integração com Databit DataSnap:**
   * Conecta na API REST DataSnap do Databit ERP (`OsController/listar` na porta 8099) para puxar detalhes cadastrais e defeitos.
   * Modal de configurações no próprio painel para ajustar IP e porta do Databit sem mexer no código.

---

## 🛠️ Como Rodar Localmente (no seu Computador)

1. **Abrir a pasta do projeto:**
   ```bash
   cd C:\Users\Tecnico\.gemini\antigravity\scratch\painel-chamados-mc
   ```

2. **Iniciar o painel:**
   * Dê dois cliques no arquivo `iniciar.bat` **OU** execute no terminal:
   ```bash
   npm start
   ```

3. **Acessar no navegador:**
   * No computador: [http://localhost:3000](http://localhost:3000)
   * No celular (na mesma rede Wi-Fi): `http://[IP-DO-SEU-COMPUTADOR]:3000`

---

## ☁️ Como Hospedar no Manus IA ou em Nuvem

Como você mencionou que irá hospedar no **Manus IA** ou em outro provedor de nuvem:

1. **Arquivos do Projeto:**
   * Todos os arquivos necessários estão prontos na pasta `painel-chamados-mc`:
     * `server.js` (Backend Node.js / Express)
     * `package.json` (Dependências leves)
     * `public/index.html`, `public/style.css`, `public/app.js` (Frontend responsivo)
     * `.env` (Variáveis de ambiente)

2. **Variáveis de Ambiente Recomendadas:**
   * `PORT`: `3000` (ou a porta atribuída pela hospedagem)
   * `DATABIT_HOST`: IP externo, DDNS ou IP interno da Mil Cópias (ex: `192.168.1.206` ou seu DDNS)
   * `DATABIT_PORT`: `8099` (porta do DataSnap)
   * `DATABIT_USER`: `ADMIN`
   * `DATABIT_PASS`: `masterkey`
   * `CHAM1_URL`: `http://databitbh.com:51202/mc/cham1.php`
