<?php
// --- Configurações e Funções Auxiliares ---

// Definição dos índices disponíveis
// 'tipo' => 'indice' (usa divisão de números-índice - mês anterior)
// 'tipo' => 'variacao' (acumula variação % mensal - mês corrente)
$indices_disponiveis = array(
    '193' => array('nome' => 'IPC-SP (FIPE - Var. %)', 'sgs' => 193, 'tipo' => 'variacao'),
    '189' => array('nome' => 'IGP-M (FGV - Índice)', 'sgs' => 189, 'tipo' => 'indice'),
    '433' => array('nome' => 'IPCA (IBGE - Índice)', 'sgs' => 433, 'tipo' => 'indice'),
    '188' => array('nome' => 'INPC (IBGE - Índice)', 'sgs' => 188, 'tipo' => 'indice'),
    // Adicione outros índices aqui se necessário, definindo o 'tipo' corretamente
);

// Função para buscar dados da API (igual à anterior)
function buscarDadosBCB($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20); // Aumentado timeout pois podem ser mais dados
    curl_setopt($ch, CURLOPT_USERAGENT, 'Calculadora Correcao PHP');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return array('success' => false, 'message' => 'Erro de cURL: ' . $error);
    if ($httpCode != 200) return array('success' => false, 'message' => 'Erro HTTP da API: ' . $httpCode . ' Response: ' . substr($response, 0, 200));
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) return array('success' => false, 'message' => 'Erro ao decodificar JSON: ' . json_last_error_msg());
    if ($data === null && strtolower(trim($response)) !== 'null') { // Verifica se json_decode falhou silenciosamente
        return array('success' => false, 'message' => 'JSON inválido ou vazio recebido da API.');
    }
    return array('success' => true, 'data' => $data);
}

// Função para validar data
function validarData($dateStr) {
    $d = DateTime::createFromFormat('d/m/Y', $dateStr);
    return $d && $d->format('d/m/Y') === $dateStr;
}

/**
 * Calcula o fator de correção para UM período específico,
 * tratando diferentes tipos de índice.
 */
function calcularFatorCorrecaoPeriodo($dt_inicio_periodo, $dt_fim_periodo, $indice_info) {
    $codigo_sgs = $indice_info['sgs'];
    $tipo_indice = $indice_info['tipo'];
    $api_data_inicial = '';
    $api_data_final = '';

    // Define as datas para consulta na API baseado no tipo de índice
    if ($tipo_indice === 'indice') {
        // Usa mês ANTERIOR ao início e mês ANTERIOR ao fim do período
        $dt_ref_inicial = clone $dt_inicio_periodo;
        $dt_ref_inicial->modify('-1 month');
        $api_data_inicial = $dt_ref_inicial->format('d/m/Y'); // Pede o dia exato do mês anterior

        $dt_ref_final = clone $dt_fim_periodo;
        $dt_ref_final->modify('-1 month');
        $api_data_final = $dt_ref_final->format('d/m/Y'); // Pede o dia exato do mês anterior ao fim

    } elseif ($tipo_indice === 'variacao') {
        // Usa o PRÓPRIO mês de início até o PRÓPRIO mês de fim do período
        // Pede o primeiro dia do mês inicial e último dia do mês final
        $api_data_inicial = $dt_inicio_periodo->format('01/m/Y');
        $ultimo_dia_mes_final = cal_days_in_month(CAL_GREGORIAN, intval($dt_fim_periodo->format('m')), intval($dt_fim_periodo->format('Y')));
        $api_data_final = $dt_fim_periodo->format($ultimo_dia_mes_final . '/m/Y');
    } else {
        return array('success' => false, 'message' => 'Tipo de índice desconhecido: ' . $tipo_indice);
    }

    // Monta URL e busca dados
    $url = sprintf(
        "https://api.bcb.gov.br/dados/serie/bcdata.sgs.%d/dados?formato=json&dataInicial=%s&dataFinal=%s",
        $codigo_sgs,
        $api_data_inicial,
        $api_data_final
    );

    $apiResponse = buscarDadosBCB($url);
    if (!$apiResponse['success']) {
        return array('success' => false, 'message' => $apiResponse['message'] . ' (URL: ' . $url . ')');
    }
    $dados_api = $apiResponse['data'];

    if (empty($dados_api)) {
         // Tenta buscar um range maior para índices mensais caso a API não retorne nos dias exatos
         if ($tipo_indice === 'indice') {
             $dt_ref_inicial_alt = clone $dt_ref_inicial;
             $dt_ref_final_alt = clone $dt_ref_final;
             $dt_ref_inicial_alt->modify('first day of this month');
             $dt_ref_final_alt->modify('last day of this month');
             $url_alt = sprintf(
                "https://api.bcb.gov.br/dados/serie/bcdata.sgs.%d/dados?formato=json&dataInicial=%s&dataFinal=%s",
                $codigo_sgs,
                $dt_ref_inicial_alt->format('d/m/Y'),
                $dt_ref_final_alt->format('d/m/Y')
            );
            $apiResponse = buscarDadosBCB($url_alt);
             if ($apiResponse['success'] && !empty($apiResponse['data'])) {
                 $dados_api = $apiResponse['data'];
                 // Logica adicional para extrair os valores corretos pode ser necessária aqui se a API retornar mais pontos
             } else {
                 return array('success' => false, 'message' => 'API não retornou dados para o período (Datas API: ' . $api_data_inicial . ' a ' . $api_data_final . ')');
             }
         } else {
            return array('success' => false, 'message' => 'API não retornou dados para o período (Datas API: ' . $api_data_inicial . ' a ' . $api_data_final . ')');
         }
    }


    // Calcula o fator baseado no tipo
    $fator_correcao = 1.0;

    if ($tipo_indice === 'indice') {
        // Precisa do primeiro e último valor do array retornado (idealmente)
        if (count($dados_api) < 1) {
             return array('success' => false, 'message' => 'Dados insuficientes da API para cálculo de índice.');
        }
        // Encontra os valores mais próximos das datas de referência
        $valor_inicial_api = null;
        $valor_final_api = null;

        // Simplificação: Pega o primeiro e último valor retornado no range.
        // Uma lógica mais robusta buscaria as datas exatas ou mais próximas.
        $valor_inicial_str = isset($dados_api[0]['valor']) ? $dados_api[0]['valor'] : null;
        $valor_final_str = isset($dados_api[count($dados_api)-1]['valor']) ? $dados_api[count($dados_api)-1]['valor'] : null;


        if ($valor_inicial_str === null || $valor_final_str === null) {
            return array('success' => false, 'message' => 'Não foi possível extrair valor inicial ou final da API.');
        }

        $indice_inicial = floatval($valor_inicial_str);
        $indice_final = floatval($valor_final_str);

        if ($indice_inicial == 0) {
            return array('success' => false, 'message' => 'Índice inicial é zero, impossível calcular.');
        }
        $fator_correcao = $indice_final / $indice_inicial;

    } elseif ($tipo_indice === 'variacao') {
        $fator_acumulado_periodo = 1.0;
        $meses_processados_periodo = 0;

        foreach ($dados_api as $item) {
             if (!isset($item['data']) || !isset($item['valor'])) continue;
             try {
                 $dt_item = DateTime::createFromFormat('d/m/Y', $item['data']);
                 if (!$dt_item) continue;

                 // Verifica se o mês/ano do item está DENTRO do período atual de 12 meses
                 $primeiro_dia_item = $dt_item->format('Y-m-01');
                 $primeiro_dia_inicio_periodo = $dt_inicio_periodo->format('Y-m-01');
                 $primeiro_dia_fim_periodo = $dt_fim_periodo->format('Y-m-01'); // Compara início do mês

                 if ($primeiro_dia_item >= $primeiro_dia_inicio_periodo && $primeiro_dia_item <= $primeiro_dia_fim_periodo) {
                    $variacao_percentual = floatval($item['valor']);
                    $multiplicador = 1 + ($variacao_percentual / 100.0);
                    $fator_acumulado_periodo *= $multiplicador;
                    $meses_processados_periodo++;
                 }
             } catch (Exception $e) { continue; }
        }
        if($meses_processados_periodo == 0) {
             return array('success' => false, 'message' => 'Nenhum dado de variação encontrado para o período específico.');
        }
        $fator_correcao = $fator_acumulado_periodo;
    }

    return array('success' => true, 'fator' => $fator_correcao);
}


// --- Processamento Principal ---
$cronograma_resultados = array();
$erro = '';
$valor_nominal_input = '';
$data_inicial_input = '';
$data_final_input = '';
$indice_sgs_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valor_nominal_input = isset($_POST['valor_nominal']) ? trim($_POST['valor_nominal']) : '';
    $data_inicial_input = isset($_POST['data_inicial']) ? trim($_POST['data_inicial']) : '';
    $data_final_input = isset($_POST['data_final']) ? trim($_POST['data_final']) : '';
    $indice_sgs_input = isset($_POST['indice_sgs']) ? trim($_POST['indice_sgs']) : '';

    // --- Validações ---
    if (empty($valor_nominal_input) || empty($data_inicial_input) || empty($data_final_input) || empty($indice_sgs_input)) {
        $erro = 'Todos os campos são obrigatórios.';
    } elseif (!is_numeric($valor_nominal_input) || floatval($valor_nominal_input) <= 0) {
        $erro = 'O valor nominal deve ser um número positivo.';
    } elseif (!validarData($data_inicial_input)) {
        $erro = 'Data inicial inválida (use DD/MM/AAAA).';
    } elseif (!validarData($data_final_input)) {
        $erro = 'Data final inválida (use DD/MM/AAAA).';
    } elseif (!isset($indices_disponiveis[$indice_sgs_input])) {
        $erro = 'Índice selecionado inválido.';
    } else {
        $dt_inicial_geral = DateTime::createFromFormat('d/m/Y', $data_inicial_input);
        $dt_final_geral = DateTime::createFromFormat('d/m/Y', $data_final_input);
        $indice_selecionado_info = $indices_disponiveis[$indice_sgs_input];
        $valor_atual = floatval($valor_nominal_input);

        if ($dt_inicial_geral >= $dt_final_geral) {
            $erro = 'A data inicial deve ser anterior à data final.';
        } else {
            // --- Loop do Cronograma ---
            $dt_periodo_inicio = clone $dt_inicial_geral;

            while ($dt_periodo_inicio <= $dt_final_geral) {
                // Define o fim do período atual (12 meses à frente ou a data final geral, o que vier primeiro)
                $dt_periodo_fim_tentativa = clone $dt_periodo_inicio;
                $dt_periodo_fim_tentativa->modify('+12 months');
                $dt_periodo_fim_tentativa->modify('-1 day'); // Fim do período de 12 meses

                // Garante que o fim do período não ultrapasse a data final geral
                $dt_periodo_fim = min($dt_periodo_fim_tentativa, $dt_final_geral);

                 // Evita loop infinito se as datas não avançarem
                if ($dt_periodo_fim < $dt_periodo_inicio) {
                    $erro = "Erro no cálculo das datas do período.";
                    break;
                }

                // Calcula a correção para este período específico
                $resultado_periodo = calcularFatorCorrecaoPeriodo($dt_periodo_inicio, $dt_periodo_fim, $indice_selecionado_info);

                if (!$resultado_periodo['success']) {
                    $erro = sprintf(
                        "Erro ao calcular período %s a %s: %s",
                        $dt_periodo_inicio->format('m/Y'),
                        $dt_periodo_fim->format('m/Y'),
                        $resultado_periodo['message']
                    );
                    $cronograma_resultados = array(); // Limpa resultados parciais em caso de erro
                    break; // Interrompe o loop em caso de erro
                }

                $fator_periodo = $resultado_periodo['fator'];
                $percentual_periodo = ($fator_periodo - 1) * 100.0;
                $valor_corrigido_periodo = $valor_atual * $fator_periodo;

                // Adiciona ao cronograma
                $cronograma_resultados[] = array(
                    'periodo_label' => $dt_periodo_inicio->format('m/Y') . ' a ' . $dt_periodo_fim->format('m/Y'),
                    'valor_inicial_periodo' => $valor_atual,
                    'percentual_periodo' => $percentual_periodo,
                    'valor_final_periodo' => $valor_corrigido_periodo
                );

                // Prepara para o próximo período
                $valor_atual = $valor_corrigido_periodo;
                $dt_periodo_inicio->modify('+12 months'); // Avança 12 meses para o início do próximo período

                 // Verifica se o próximo início ultrapassou a data final geral
                if($dt_periodo_inicio > $dt_final_geral) {
                     break;
                }

            } // Fim while
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculadora de Correção Monetária - Cronograma</title>
    <style>
        /* (Estilos CSS iguais ao exemplo anterior, adicionando estilo para tabela) */
        body { font-family: sans-serif; line-height: 1.6; padding: 20px; max-width: 750px; margin: auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold;}
        input[type="text"], input[type="number"], select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1em;}
        button:hover { background-color: #0056b3; }
        .error { color: #dc3545; border: 1px solid #dc3545; padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .results-table { margin-top: 25px; width: 100%; border-collapse: collapse; }
        .results-table th, .results-table td { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
        .results-table th { background-color: #e9ecef; }
        .results-table td:nth-child(2),
        .results-table td:nth-child(4) { text-align: right; } /* Alinha valores à direita */
         .results-table td:nth-child(3) { text-align: center; } /* Alinha percentual ao centro */
    </style>
</head>
<body>

    <h1>Calculadora de Correção Monetária - Cronograma Anual</h1>
    <p>Calcula o reajuste anual acumulado com base no índice selecionado.</p>

    <?php if ($erro): ?>
        <div class="error"><?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>

    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
        <div class="form-group">
            <label for="valor_nominal">Valor Nominal Inicial (R$):</label>
            <input type="number" id="valor_nominal" name="valor_nominal" step="0.01" required
                   value="<?php echo htmlspecialchars($valor_nominal_input); ?>">
        </div>
        <div class="form-group">
            <label for="data_inicial">Data Inicial Contrato:</label>
            <input type="text" id="data_inicial" name="data_inicial" placeholder="DD/MM/AAAA" required
                   value="<?php echo htmlspecialchars($data_inicial_input); ?>">
        </div>
        <div class="form-group">
            <label for="data_final">Data Final Contrato:</label>
            <input type="text" id="data_final" name="data_final" placeholder="DD/MM/AAAA" required
                   value="<?php echo htmlspecialchars($data_final_input); ?>">
        </div>
         <div class="form-group">
            <label for="indice_sgs">Índice de Correção:</label>
            <select id="indice_sgs" name="indice_sgs" required>
                <option value="">-- Selecione um Índice --</option>
                <?php foreach ($indices_disponiveis as $codigo => $info): ?>
                    <option value="<?php echo $codigo; ?>" <?php echo ($indice_sgs_input == $codigo) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($info['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit">Gerar Cronograma</button>
    </form>

    <?php if (!empty($cronograma_resultados)): ?>
        <h2>Cronograma de Correção</h2>
        <table class="results-table">
            <thead>
                <tr>
                    <th>Período</th>
                    <th>Valor Inicial (R$)</th>
                    <th>Var. Percentual (%)</th>
                    <th>Valor Reajustado (R$)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cronograma_resultados as $linha): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($linha['periodo_label']); ?></td>
                        <td><?php echo number_format($linha['valor_inicial_periodo'], 2, ',', '.'); ?></td>
                        <td><?php echo number_format($linha['percentual_periodo'], 6, ',', '.'); ?></td>
                        <td><?php echo number_format($linha['valor_final_periodo'], 2, ',', '.'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</body>
</html>
