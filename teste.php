<?php
// --- Configurações e Funções Auxiliares ---

// Código SGS para IPC-SP (FIPE - Variação % mensal)
define('CODIGO_SGS_IPCS', 193);

// Função para buscar dados da API do BCB usando cURL
function buscarDadosBCB($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout de 15 segundos
    curl_setopt($ch, CURLOPT_USERAGENT, 'Calculadora Correcao PHP'); // É bom identificar seu script
    // Para HTTPS (API do BCB usa HTTPS)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Mais seguro, mas pode precisar de ajuste no servidor
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return array('success' => false, 'message' => 'Erro de cURL: ' . $error);
    }

    if ($httpCode != 200) {
        return array('success' => false, 'message' => 'Erro HTTP da API: ' . $httpCode . ' - ' . substr($response, 0, 200));
    }

    $data = json_decode($response, true); // Decodifica como array associativo

    if (json_last_error() !== JSON_ERROR_NONE) {
        return array('success' => false, 'message' => 'Erro ao decodificar JSON: ' . json_last_error_msg());
    }

    return array('success' => true, 'data' => $data);
}

// Função para validar data no formato DD/MM/AAAA
function validarData($dateStr) {
    $d = DateTime::createFromFormat('d/m/Y', $dateStr);
    return $d && $d->format('d/m/Y') === $dateStr;
}

// --- Processamento do Formulário ---
$resultados = null;
$erro = '';
$valor_nominal_input = '';
$data_inicial_input = '';
$data_final_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Pega e limpa os dados do POST
    $valor_nominal_input = isset($_POST['valor_nominal']) ? trim($_POST['valor_nominal']) : '';
    $data_inicial_input = isset($_POST['data_inicial']) ? trim($_POST['data_inicial']) : '';
    $data_final_input = isset($_POST['data_final']) ? trim($_POST['data_final']) : '';

    // --- Validações ---
    if (empty($valor_nominal_input) || empty($data_inicial_input) || empty($data_final_input)) {
        $erro = 'Todos os campos são obrigatórios.';
    } elseif (!is_numeric($valor_nominal_input) || floatval($valor_nominal_input) <= 0) {
        $erro = 'O valor nominal deve ser um número positivo.';
    } elseif (!validarData($data_inicial_input)) {
        $erro = 'Data inicial inválida (use DD/MM/AAAA).';
    } elseif (!validarData($data_final_input)) {
        $erro = 'Data final inválida (use DD/MM/AAAA).';
    } else {
        $dt_inicial = DateTime::createFromFormat('d/m/Y', $data_inicial_input);
        $dt_final = DateTime::createFromFormat('d/m/Y', $data_final_input);

        if ($dt_inicial >= $dt_final) {
            $erro = 'A data inicial deve ser anterior à data final.';
        } else {
            // --- Preparação para a API ---
            $valor_nominal = floatval($valor_nominal_input);

            // Formata datas para a API: primeiro dia do mês inicial até último dia do mês final
            $api_data_inicial = $dt_inicial->format('01/m/Y');
            $ultimo_dia_mes_final = cal_days_in_month(CAL_GREGORIAN, intval($dt_final->format('m')), intval($dt_final->format('Y')));
            $api_data_final = $dt_final->format($ultimo_dia_mes_final . '/m/Y');

            $url = sprintf(
                "https://api.bcb.gov.br/dados/serie/bcdata.sgs.%d/dados?formato=json&dataInicial=%s&dataFinal=%s",
                CODIGO_SGS_IPCS,
                $api_data_inicial,
                $api_data_final
            );

            // --- Busca e Processamento dos Dados ---
            $apiResponse = buscarDadosBCB($url);

            if (!$apiResponse['success']) {
                $erro = 'Erro ao buscar dados da API: ' . $apiResponse['message'];
            } else {
                $dados_mensais = $apiResponse['data'];

                if (empty($dados_mensais)) {
                    $erro = 'A API não retornou dados para o período solicitado.';
                } else {
                    $fator_acumulado = 1.0;
                    $meses_processados = 0;
                    $debug_mensal = array(); // Para guardar detalhes do cálculo

                    // Loop para acumular as variações mensais DENTRO do período solicitado
                    foreach ($dados_mensais as $item) {
                        if (!isset($item['data']) || !isset($item['valor'])) {
                            continue; // Pula item mal formatado
                        }

                        try {
                            $dt_item = DateTime::createFromFormat('d/m/Y', $item['data']);
                            if (!$dt_item) continue; // Pula data inválida no item

                            // Verifica se o mês/ano do item está dentro do período desejado (inclusive)
                            // Compara o primeiro dia do mês do item com o primeiro dia dos meses inicial e final
                            $primeiro_dia_item = $dt_item->format('Y-m-01');
                            $primeiro_dia_inicial = $dt_inicial->format('Y-m-01');
                            $primeiro_dia_final = $dt_final->format('Y-m-01');

                            if ($primeiro_dia_item >= $primeiro_dia_inicial && $primeiro_dia_item <= $primeiro_dia_final) {
                                $variacao_percentual = floatval($item['valor']);
                                $multiplicador = 1 + ($variacao_percentual / 100.0);
                                $fator_acumulado *= $multiplicador;
                                $meses_processados++;
                                $debug_mensal[] = sprintf(
                                    "[%s: Var=%.2f%%, Mult=%.6f, Acum=%.8f]",
                                    $dt_item->format('m/Y'),
                                    $variacao_percentual,
                                    $multiplicador,
                                    $fator_acumulado
                                );
                            }
                        } catch (Exception $e) {
                            // Ignora item se houver erro de conversão ou data
                            continue;
                        }
                    }

                    if ($meses_processados == 0) {
                        $erro = 'Nenhum dado mensal válido encontrado no período especificado.';
                    } else {
                        // --- Cálculos Finais ---
                        $percentual_total = ($fator_acumulado - 1) * 100.0;
                        $valor_corrigido = $valor_nominal * $fator_acumulado;

                        // Guarda resultados para exibição
                        $resultados = array(
                            'fator' => $fator_acumulado,
                            'percentual' => $percentual_total,
                            'valor_corrigido' => $valor_corrigido,
                            'debug_mensal' => $debug_mensal // Opcional: para depuração
                        );
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculadora de Correção Monetária (IPC-SP FIPE)</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; padding: 20px; max-width: 600px; margin: auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold;}
        input[type="text"], input[type="number"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1em;}
        button:hover { background-color: #0056b3; }
        .error { color: #dc3545; border: 1px solid #dc3545; padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .results { margin-top: 20px; padding: 15px; border: 1px solid #ccc; border-radius: 4px; background-color: #f8f9fa; }
        .results h3 { margin-top: 0; }
        .results p { margin: 5px 0; }
        .results strong { display: inline-block; min-width: 250px; }
        .debug-info { font-size: 0.8em; color: #666; margin-top: 10px; word-wrap: break-word; }
    </style>
</head>
<body>

    <h1>Calculadora de Correção Monetária</h1>
    <p>Utiliza o índice <strong>IPC-SP (FIPE - Variação % Mensal - SGS 193)</strong>.</p>

    <?php if ($erro): ?>
        <div class="error"><?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>

    <form action="calculadora_correcao.php" method="post">
        <div class="form-group">
            <label for="valor_nominal">Valor Nominal (R$):</label>
            <input type="number" id="valor_nominal" name="valor_nominal" step="0.01" required
                   value="<?php echo htmlspecialchars($valor_nominal_input); ?>">
        </div>
        <div class="form-group">
            <label for="data_inicial">Data Inicial:</label>
            <input type="text" id="data_inicial" name="data_inicial" placeholder="DD/MM/AAAA" required
                   value="<?php echo htmlspecialchars($data_inicial_input); ?>">
        </div>
        <div class="form-group">
            <label for="data_final">Data Final:</label>
            <input type="text" id="data_final" name="data_final" placeholder="DD/MM/AAAA" required
                   value="<?php echo htmlspecialchars($data_final_input); ?>">
        </div>
        <button type="submit">Calcular Correção</button>
    </form>

    <?php if ($resultados !== null): ?>
        <div class="results">
            <h3>Resultados Calculados</h3>
            <p><strong>Índice de Correção no Período:</strong> <?php echo number_format($resultados['fator'], 8, ',', '.'); ?></p>
            <p><strong>Valor Percentual Correspondente:</strong> <?php echo number_format($resultados['percentual'], 6, ',', '.'); ?> %</p>
            <p><strong>Valor Corrigido na Data Final:</strong> R$ <?php echo number_format($resultados['valor_corrigido'], 2, ',', '.'); ?></p>

            <?php /* Descomente a linha abaixo para ver os detalhes mensais (para depuração)
            <div class="debug-info">
                <strong>Detalhes Mensais (Debug):</strong><br>
                <?php echo implode('<br>', array_map('htmlspecialchars', $resultados['debug_mensal'])); ?>
            </div>
            */ ?>
        </div>
    <?php endif; ?>

</body>
</html>
