<?php
// --- Configurações e Funções Auxiliares ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('CODIGO_SGS_IPCS', 193); // IPC-SP (FIPE - Variação % mensal)

// Função buscarDadosBCB (sem alterações - já postada anteriormente)
function buscarDadosBCB($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Calculadora Correcao PHP v3');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return array('success' => false, 'message' => 'Erro de cURL: ' . $error);
    if ($httpCode != 200) {
        $api_error_details = json_decode($response, true);
        $details_message = isset($api_error_details['message']) ? $api_error_details['message'] : substr($response, 0, 200);
        return array('success' => false, 'message' => 'Erro HTTP da API: ' . $httpCode . ' - ' . $details_message);
    }
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) return array('success' => false, 'message' => 'Erro ao decodificar JSON: ' . json_last_error_msg());
    return array('success' => true, 'data' => $data);
}


// Função validarData (sem alterações - já postada anteriormente)
function validarData($dateStr) {
    $d = DateTime::createFromFormat('d/m/Y', $dateStr);
    return $d && $d->format('d/m/Y') === $dateStr && intval($d->format('Y')) > 1900;
}

// --- Processamento do Formulário ---
$resultados_finais = null;
$tabela_resultados = array();
$erro = '';
$valor_nominal_input = '';
$data_inicial_input = '';
$data_final_input = '';

// Definir limites de data para validação
$hoje = new DateTime(); // Data/Hora Atual
$primeiro_dia_mes_passado = (new DateTime())->modify('first day of last month')->setTime(0, 0, 0);
$fim_do_dia_hoje = (new DateTime())->setTime(23, 59, 59); // Fim do dia atual

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $dt_inicial = DateTime::createFromFormat('d/m/Y H:i:s', $data_inicial_input . ' 00:00:00');
        $dt_final = DateTime::createFromFormat('d/m/Y H:i:s', $data_final_input . ' 23:59:59');

        if ($dt_inicial >= $dt_final) {
            $erro = 'A data inicial deve ser anterior à data final.';
        } elseif ($dt_inicial < $primeiro_dia_mes_passado || $dt_final > $fim_do_dia_hoje) {
            $erro = 'O período selecionado deve estar entre o início do mês passado ('
                   . $primeiro_dia_mes_passado->format('d/m/Y') . ') e a data atual ('
                   . $hoje->format('d/m/Y') . ').';
        } else {
            // --- Preparação ---
            $valor_nominal = floatval($valor_nominal_input);
            $dia_inicial_usuario = intval($dt_inicial->format('d'));

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
                $dados_mensais_api = $apiResponse['data'];

                if (empty($dados_mensais_api)) {
                    $erro = 'A API não retornou dados para o período solicitado.';
                } else {
                    // --- Organiza dados da API por Mês/Ano ---
                    $dados_por_mes = array();
                    foreach($dados_mensais_api as $item) {
                         if (!isset($item['data']) || !isset($item['valor'])) continue;
                         $dt_item = DateTime::createFromFormat('d/m/Y', $item['data']);
                         if ($dt_item) {
                             $mes_ano_chave = $dt_item->format('Y-m');
                             $dados_por_mes[$mes_ano_chave] = ['variacao' => floatval($item['valor'])];
                         }
                    }

                    // --- Verificação de Completude e Cálculo ANUAL com Tabela Mensal ---
                    $valor_base_periodo_atual = $valor_nominal; // Valor no início do bloco de 12m atual
                    $fator_bloco_12m = 1.0; // Fator acumulado DENTRO do bloco de 12m atual
                    $meses_no_bloco_atual = 0; // Contador de meses no bloco atual
                    $fator_total_aplicado = 1.0; // Fator total efetivamente aplicado (considerando blocos anteriores)

                    $periodo = new DatePeriod(
                        (clone $dt_inicial)->modify('first day of this month'),
                        new DateInterval('P1M'),
                        (clone $dt_final)->modify('first day of next month')
                    );

                    $todos_meses_ok = true;
                    foreach ($periodo as $data_mes_atual) {
                        $mes_ano_chave_atual = $data_mes_atual->format('Y-m');

                        // Verifica se temos dados para este mês
                        if (!isset($dados_por_mes[$mes_ano_chave_atual])) {
                            $erro = "Erro: Dados da API não encontrados para o mês " . $data_mes_atual->format('m/Y') . ".";
                            $todos_meses_ok = false;
                            $tabela_resultados = array();
                            break;
                        }

                        // Pega a variação do mês atual
                        $variacao_percentual = $dados_por_mes[$mes_ano_chave_atual]['variacao'];
                        $multiplicador = 1 + ($variacao_percentual / 100.0);

                        // Acumula o fator DENTRO do bloco atual de 12 meses
                        $fator_bloco_12m *= $multiplicador;
                        $meses_no_bloco_atual++;

                        // --- Prepara dados para a linha da tabela deste mês ---
                        // Data de exibição usa o dia do usuário
                        $data_display = clone $data_mes_atual;
                        $ultimo_dia_mes_atual = intval($data_display->format('t'));
                        $dia_para_usar = ($dia_inicial_usuario <= $ultimo_dia_mes_atual) ? $dia_inicial_usuario : $ultimo_dia_mes_atual;
                        $data_display->setDate(intval($data_display->format('Y')), intval($data_display->format('m')), $dia_para_usar);
                        $data_display_str = $data_display->format('d/m/Y');

                        // Valor corrigido exibido na tabela é o valor BASE do período atual (só muda a cada 12m)
                        $valor_corrigido_display = $valor_base_periodo_atual;
                        // Fator acumulado exibido é o fator TOTAL aplicado até o início deste período
                        $fator_acumulado_display = $fator_total_aplicado;
                        // Diferença é baseada no valor exibido
                        $diferenca_nominal = $valor_corrigido_display - $valor_nominal;

                        $tabela_resultados[] = array(
                            'data_display' => $data_display_str,
                            'variacao' => $variacao_percentual,
                            'fator_acumulado' => $fator_acumulado_display, // Fator aplicado no início do mês
                            'valor_corrigido' => $valor_corrigido_display, // Valor baseado no fator acima
                            'diferenca' => $diferenca_nominal
                        );

                        // --- Verifica se completou um bloco de 12 meses ---
                        if ($meses_no_bloco_atual == 12) {
                            // Aplica a correção anual ao valor base
                            $valor_base_periodo_atual *= $fator_bloco_12m;
                            // Atualiza o fator total aplicado
                            $fator_total_aplicado *= $fator_bloco_12m;
                            // Reseta para o próximo bloco
                            $fator_bloco_12m = 1.0;
                            $meses_no_bloco_atual = 0;
                        }
                    } // Fim do loop pelos meses

                    // --- Calcula Resultados Finais após o loop ---
                    if ($todos_meses_ok && !empty($tabela_resultados)) {
                        // O valor final considera o último bloco (completo ou parcial)
                        // Se houve meses no último bloco parcial, aplica seu fator
                        if ($meses_no_bloco_atual > 0) {
                             $valor_corrigido_final = $valor_base_periodo_atual * $fator_bloco_12m;
                             $fator_total_final = $fator_total_aplicado * $fator_bloco_12m;
                        } else {
                             // Se terminou exatamente em um bloco de 12m
                             $valor_corrigido_final = $valor_base_periodo_atual;
                             $fator_total_final = $fator_total_aplicado;
                        }
                        $percentual_total = ($fator_total_final - 1) * 100.0;

                        $resultados_finais = array(
                           'fator' => $fator_total_final,
                           'percentual' => $percentual_total,
                           'valor_corrigido' => $valor_corrigido_final,
                        );
                    } elseif ($todos_meses_ok && empty($tabela_resultados)) {
                         $erro = "Não foi possível gerar resultados para o período.";
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
    <title>Calculadora Correção Anual Detalhada (IPC-SP FIPE)</title>
    <style>
        /* Mesmos estilos da versão anterior */
        body { font-family: sans-serif; line-height: 1.6; padding: 20px; max-width: 800px; margin: auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold;}
        input[type="text"], input[type="number"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1em;}
        button:hover { background-color: #0056b3; }
        .error { color: #dc3545; border: 1px solid #dc3545; padding: 10px; margin-bottom: 15px; border-radius: 4px; background-color: #f8d7da; }
        .results-summary { margin-top: 20px; margin-bottom: 20px; padding: 15px; border: 1px solid #ccc; border-radius: 4px; background-color: #e9ecef; }
        .results-summary h3 { margin-top: 0; }
        .results-summary p { margin: 5px 0; }
        .results-summary strong { display: inline-block; min-width: 250px; }
        .results-table { margin-top: 20px; width: 100%; border-collapse: collapse; }
        .results-table th, .results-table td { border: 1px solid #dee2e6; padding: 8px; text-align: right; }
        .results-table th { background-color: #f8f9fa; text-align: center; }
        .results-table td:first-child { text-align: center; } /* Centraliza data */
    </style>
</head>
<body>

    <h1>Calculadora de Correção Monetária (Base Anual)</h1>
    <p>Utiliza o índice <strong>IPC-SP (FIPE - Variação % Mensal - SGS 193)</strong>.</p>
    <p>A correção é aplicada em blocos de 12 meses completos. O valor final considera o período parcial restante.</p>
    <p>Permite datas entre <strong><?php echo $primeiro_dia_mes_passado->format('d/m/Y'); ?></strong> e <strong><?php echo $hoje->format('d/m/Y'); ?></strong>.</p>

    <?php if ($erro): ?>
        <div class="error"><?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>

    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
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

    <?php if ($resultados_finais !== null && !empty($tabela_resultados)): ?>
        <div class="results-summary">
            <h3>Resultados Finais</h3>
            <p><strong>Valor Nominal Original:</strong> R$ <?php echo number_format(floatval($valor_nominal_input), 2, ',', '.'); ?></p>
            <p><strong>Período Calculado:</strong> <?php echo htmlspecialchars($data_inicial_input); ?> a <?php echo htmlspecialchars($data_final_input); ?></p>
            <p><strong>Índice de Correção Total no Período:</strong> <?php echo number_format($resultados_finais['fator'], 8, ',', '.'); ?></p>
            <p><strong>Variação Percentual Total no Período:</strong> <?php echo number_format($resultados_finais['percentual'], 6, ',', '.'); ?> %</p>
            <p><strong>Valor Corrigido na Data Final:</strong> R$ <?php echo number_format($resultados_finais['valor_corrigido'], 2, ',', '.'); ?></p>
            <p><strong>Diferença Total do Valor Nominal:</strong> R$ <?php echo number_format($resultados_finais['valor_corrigido'] - floatval($valor_nominal_input), 2, ',', '.'); ?></p>
        </div>

        <h2>Cronograma Detalhado Mês a Mês (Base Anual)</h2>
        <table class="results-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Variação % Mês</th>
                    <th>Fator Aplicado (Início Mês)</th> <th>Valor Corrigido (Base Anual)</th> <th>Diferença do Valor Nominal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tabela_resultados as $linha): ?>
                <tr>
                    <td><?php echo htmlspecialchars($linha['data_display']); ?></td>
                    <td><?php echo number_format($linha['variacao'], 2, ',', '.'); ?> %</td>
                    <td><?php echo number_format($linha['fator_acumulado'], 8, ',', '.'); ?></td> <td>R$ <?php echo number_format($linha['valor_corrigido'], 2, ',', '.'); ?></td> <td>R$ <?php echo number_format($linha['diferenca'], 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</body>
</html>
