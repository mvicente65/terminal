<?php
session_start();

// Captura o diretório vindo dos scripts de inicialização (executar.bat / executar.sh)
if (isset($_GET['dir']) && !empty($_GET['dir'])) {
    $_SESSION['execution_dir'] = $_GET['dir'];
}

// Backend API para processamento AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Garante que nenhum Warning/Notice do PHP suje a saída JSON
    ini_set('display_errors', 0);
    error_reporting(0);

    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

	if ($action === 'execute') {
        $cmd = trim($_POST['comando'] ?? '');
        $dir = $_SESSION['execution_dir'] ?? __DIR__;
        if (!file_exists($dir) || !is_dir($dir)) {
            $dir = __DIR__;
        }

        if (empty($cmd)) {
            echo json_encode(['output' => "Erro: Nenhum comando informado."]);
            exit;
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            // cd /d garante a troca de unidade de disco
            $commandToRun = 'cd /d "' . addslashes($dir) . '" && ' . $cmd . ' 2>&1';
        } else {
            $commandToRun = 'cd "' . addslashes($dir) . '" && ' . $cmd . ' 2>&1';
        }

        // Executa o comando no SO
        $output = shell_exec($commandToRun);

        if ($output === null || $output === false) {
            $output = "Comando executado sem retorno visual.";
        } else {
            // Garante a conversão de encoding do terminal (Windows CP850/Windows-1252) para UTF-8
            if (!mb_detect_encoding($output, 'UTF-8', true)) {
                $output = mb_convert_encoding($output, 'UTF-8', 'Windows-1252');
            }
        }

        // json_encode com flag para ignorar/substituir caracteres inválidos se houver
        $json = json_encode(['output' => $output], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            echo json_encode(['output' => "Erro ao codificar o retorno em JSON: " . json_last_error_msg()]);
            exit;
        }

        echo $json;
        exit;
    }

    if ($action === 'save_commands') {
        $items = json_decode($_POST['items'] ?? '[]', true);
        $jsonFile = __DIR__ . '/comandos.json';
        
        $existing = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];
        if (!is_array($existing)) $existing = [];

        $addedCount = 0;
        foreach ($items as $item) {
            if (isset($item['salvar']) && $item['salvar'] === 'S' && !empty(trim($item['comando']))) {
                $cmdTrim = trim($item['comando']);
                $exists = false;
                foreach ($existing as $ex) {
                    if (strcasecmp($ex['comando'], $cmdTrim) === 0) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $existing[] = [
                        'comando' => $cmdTrim,
                        'descricao' => trim($item['descricao'] ?? '')
                    ];
                    $addedCount++;
                }
            }
        }

        file_put_contents($jsonFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true, 'added' => $addedCount]);
        exit;
    }
}

// Carrega os comandos do arquivo JSON para o Autocomplete
$comandosSalvos = [];
if (file_exists(__DIR__ . '/comandos.json')) {
    $comandosSalvos = json_decode(file_get_contents(__DIR__ . '/comandos.json'), true) ?: [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Terminal de Execução OS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
/* Layout responsivo ocupando 100% da largura e altura do navegador */
html, body {
    height: 100%;
    width: 100%;
    margin: 0;
    padding: 0;
}

body {
    background-color: #f8f9fa;
    display: flex;
    flex-direction: column;
}

/* Container do terminal preenchendo a largura total e o restante da altura */
#areaTerminal {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
    width: 100%;
    border-radius: 0;
}

#areaTerminal .card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
    width: 100%;
}

/* Caixa do terminal com rolagem vertical e horizontal (sem quebra de linha) */
.terminal-box {
    background-color: #000;
    color: #00ff00;
    font-family: 'Courier New', Courier, monospace;
    padding: 15px;
    flex: 1;
    height: 100%;
    width: 100%;
    overflow-x: auto;
    overflow-y: auto;
    white-space: pre;
    border-radius: 0;
    box-sizing: border-box;
}
        .autocomplete-suggestions {
            position: absolute;
            border: 1px solid #ced4da;
            background: #fff;
            z-index: 1000;
            max-height: 200px;
            overflow-y: auto;
            width: calc(100% - 24px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .autocomplete-suggestion {
            padding: 8px 12px;
            cursor: pointer;
        }
        .autocomplete-suggestion:hover { background-color: #e9ecef; }
        .autocomplete-desc { color: #6c757d; font-size: 0.85em; font-style: italic; }
    </style>
</head>
<body class="container py-4">

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">Executor de Comandos</h5>
        </div>
        <div class="card-body">
            <p><strong>Diretório de Execução (SESSION):</strong> <code><?= htmlspecialchars($_SESSION['execution_dir'] ?? 'Não definido (usando diretório atual)') ?></code></p>
            
            <form id="formComando" autocomplete="off" onsubmit="return false;">
                <div class="position-relative mb-3">
                    <label for="inputComando" class="form-label">Comando:</label>
                    <input type="text" class="form-control" id="inputComando" placeholder="Digite um comando...">
                    <div id="suggestions" class="autocomplete-suggestions d-none"></div>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" id="btnExecutar" onclick="executarComando()">Executar</button>
                    <button type="button" class="btn btn-warning d-none" id="btnEditar" onclick="abrirEdicao()">Editar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ÁREA 1: Saída do Terminal (Preto com Scroll Vertical) -->
    <div id="areaTerminal" class="card shadow-sm">
        <div class="card-header bg-secondary text-white">Saída da Execução</div>
        <div class="card-body p-0">
            <div id="outputTerminal" class="terminal-box">Aguardando execução...</div>
        </div>
    </div>

    <!-- ÁREA 2: Formulário de Edição/Cadastramento (Inicia Oculto) -->
    <div id="areaEdicao" class="card shadow-sm d-none">
        <div class="card-header bg-secondary text-white">Cadastrar/Salvar Comandos no Autocomplete</div>
        <div class="card-body">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 45%;">Comando</th>
                        <th style="width: 40%;">Descrição</th>
                        <th style="width: 15%;">Salvar (S/N) ?</th>
                    </tr>
                </thead>
                <tbody id="tbodyEdicao"></tbody>
            </table>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success" onclick="salvarNovosComandos()">SALVAR</button>
                <button type="button" class="btn btn-secondary" onclick="cancelarEdicao()">CANCELAR</button>
            </div>
        </div>
    </div>

<script>
const sugestoesBase = <?= json_encode($comandosSalvos) ?>;
const inputComando = document.getElementById('inputComando');
const suggestionsBox = document.getElementById('suggestions');
let ultimoComandoExecutado = "";

// --- Autocomplete ---
inputComando.addEventListener('input', function() {
    const val = this.value;
    suggestionsBox.innerHTML = '';
    
    if (!val) {
        suggestionsBox.classList.add('d-none');
        return;
    }

    const cursorPosition = this.selectionStart;
    const textBeforeCursor = val.substring(0, cursorPosition);
    const words = textBeforeCursor.split(/\s+/);
    const currentWord = words[words.length - 1];

    if (!currentWord) {
        suggestionsBox.classList.add('d-none');
        return;
    }

    const matches = sugestoesBase.filter(item => 
        item.comando.toLowerCase().includes(currentWord.toLowerCase())
    );

    if (matches.length === 0) {
        suggestionsBox.classList.add('d-none');
        return;
    }

    matches.forEach(item => {
        const div = document.createElement('div');
        div.className = 'autocomplete-suggestion';
        div.innerHTML = `<strong>${item.comando}</strong> ${item.descricao ? `<span class="autocomplete-desc">- "${item.descricao}"</span>` : ''}`;
        
        div.onclick = function() {
            words[words.length - 1] = item.comando;
            const newText = words.join(' ') + val.substring(cursorPosition);
            inputComando.value = newText;
            suggestionsBox.classList.add('d-none');
            inputComando.focus();
        };
        suggestionsBox.appendChild(div);
    });

    suggestionsBox.classList.remove('d-none');
});

document.addEventListener('click', function(e) {
    if (e.target !== inputComando) suggestionsBox.classList.add('d-none');
});

// --- Execução ---
function executarComando() {
    const comando = inputComando.value;
    if (!comando.trim()) return;

    ultimoComandoExecutado = comando;
    const outputDiv = document.getElementById('outputTerminal');
    outputDiv.innerText = "Executando no SO...";

    const formData = new FormData();
    formData.append('action', 'execute');
    formData.append('comando', comando);

    fetch('index.php', { method: 'POST', body: formData })
    .then(async res => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error("O servidor retornou HTML/Texto em vez de JSON:\n\n" + text);
        }
    })
    .then(data => {
        outputDiv.innerText = data.output;
        document.getElementById('btnEditar').classList.remove('d-none');
    })
    .catch(err => {
        outputDiv.innerText = err.message;
    });
}

// --- Edição e Extração de Termos ---
function abrirEdicao() {
    const tbody = document.getElementById('tbodyEdicao');
    tbody.innerHTML = '';

    const comandoBruto = ultimoComandoExecutado.trim();

    // 1. Regex para extrair parâmetros/palavras preservando o que está entre aspas
    const regexTokens = /"([^"]+)"|'([^']+)'|(\S+)/g;
    const tokens = [];
    let match;

    while ((match = regexTokens.exec(comandoBruto)) !== null) {
        // Captura o valor (sem as aspas envolventes, se existirem)
        const token = match[1] || match[2] || match[3];
        if (token) {
            tokens.push(token);
        }
    }

    // 2. Monta a lista inicial: primeiro o comando completo, depois as palavras/URLs extraídas
    const itensParaEditar = [comandoBruto, ...tokens];

    // 3. Remove duplicatas mantendo a ordem de aparição
    const itensUnicos = [...new Set(itensParaEditar)];

    // 4. Renderiza as linhas na tabela de edição
    itensUnicos.forEach(item => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" class="form-control cmd-input" value="${item.replace(/"/g, '&quot;')}"></td>
            <td><input type="text" class="form-control desc-input" placeholder="[comando explicado...]"></td>
            <td>
                <select class="form-select save-select">
                    <option value="N" selected>N</option>
                    <option value="S">S</option>
                </select>
            </td>
        `;
        tbody.appendChild(tr);
    });

    // Alterna a exibição das telas
    document.getElementById('areaTerminal').classList.add('d-none');
    document.getElementById('areaEdicao').classList.remove('d-none');
}

function cancelarEdicao() {
    document.getElementById('areaEdicao').classList.add('d-none');
    document.getElementById('areaTerminal').classList.remove('d-none');
}

function salvarNovosComandos() {
    const rows = document.querySelectorAll('#tbodyEdicao tr');
    const payload = [];

    rows.forEach(row => {
        payload.push({
            comando: row.querySelector('.cmd-input').value,
            descricao: row.querySelector('.desc-input').value,
            salvar: row.querySelector('.save-select').value
        });
    });

    const formData = new FormData();
    formData.append('action', 'save_commands');
    formData.append('items', JSON.stringify(payload));

    fetch('index.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.added + " novos comandos foram salvos com sucesso!");
            location.reload(); // Recarrega a página para atualizar as sugestões do autocomplete
        }
    });
}
</script>
</body>
</html>
