<?php
// --- Configurações e Funções Auxiliares ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo'); // Define fuso horário

// --- Definição dos Índices Disponíveis ---
// Adicione mais índices aqui conforme necessário
// Tipos: 'variacao_mensal', 'numero_indice'
$indices_disponiveis = [
    '193' => ['nome' => 'IPC-SP (FIPE)', 'tipo' => 'variacao_mensal'],
    '433' => ['nome' => 'IPCA (IBGE)', 'tipo' => 'numero_indice'],
    '13522' => ['nome' => 'IPCA (IBGE - desde 1979)', 'tipo' => 'numero_indice'],
    '189' => ['nome' => 'IGP-M (FGV)', 'tipo' => 'numero_indice'],
    '188' => ['nome' => 'INPC (IBGE)', 'tipo' => 'numero_indice'],
    // Adicione mais códigos e tipos aqui
];


// Função buscarDadosBCB (sem alterações)
function buscarDadosBCB($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25); // Aumentado timeout
    curl_setopt($ch, CURLOPT_USERAGENT, 'Calculadora Correcao PHP v4');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return array('success' => false, 'message' => 'Erro de cURL: ' . $error);
    if ($httpCode != 200) {
        $api_error_details = json_decode($response, true);
        $details_message = isset($api_error_details['detail']) ? $api_error_details['detail'] : (isset($api_error_details['message']) ? $api_error_details['message'] : substr($response, 0, 200));
        return array('success' => false, 'message' => 'Erro HTTP da API: ' . $httpCode . ' - ' . $details_message);
    }
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) return array('success' => false, 'message' => 'Erro ao decodificar JSON: ' . json_last_error_msg());
    return array('success' => true, 'data' => $data);
}


// Função validarData (sem alterações)
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
$indice_sgs_input = ''; // Para guardar seleção do usuário
$metodo_calculo_usado = ''; // Para informar o usuário

// Definir limites de data para validação
$hoje = new DateTimeImmutable(); // Usar Immutable para segurança
// *** LIMITE SUPERIOR AGORA É O FIM DO MÊS PASSADO ***
$ultimo_dia_mes_passado = $hoje->modify('last day of last month')->setTime(23, 59, 59);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Pega e limpa os dados do POST
    $valor_nominal_input = isset($_POST['valor_nominal']) ? trim($_POST['valor_nominal']) : '';
    $data_inicial_input = isset($_POST['data_inicial']) ? trim($_POST['data_inicial']) : '';
    $data_final_input = isset($_POST['data_final']) ? trim($_POST['data_final']) : '';
    $indice_sgs_input = isset($_POST['indice_sgs']) ? trim($_POST['indice_sgs']) : '';

    // --- Validações ---
    if (empty($valor_nominal_input) || empty($data_inicial_input) || empty($data_final_input) || empty($indice_sgs_input)) {
        $erro = 'Todos os campos são obrigatórios.';
    } elseif (!is_numeric($valor_nominal_input) || floatval($valor_nominal_input) <= 0) {
        $erro = 'O valor nominal deve ser um número positivo.';
    } elseif (!isset($indices_disponiveis[$indice_sgs_input])) {
         $erro = 'Índice selecionado inválido.';
    } elseif (!validarData($data_inicial_input)) {
        $erro = 'Data inicial inválida (use DD/MM/AAAA).';
    } elseif (!validarData($data_final_input)) {
        $erro = 'Data final inválida (use DD/MM/AAAA).';
    } else {
        $dt_inicial = DateTime::createFromFormat('d/m/Y H:i:s', $data_inicial_input . ' 00:00:00');
        $dt_final = DateTime::createFromFormat('d/m/Y H:i:s', $data_final_input . ' 23:59:59');
        $indice_info = $indices_disponiveis[$indice_sgs_input];
        $tipo_calculo = $indice_info['tipo'];
        $codigo_sgs_selecionado = $indice_sgs_input;

        if ($dt_inicial >= $dt_final) {
            $erro = 'A data inicial deve ser anterior à data final.';
        // *** NOVA VALIDAÇÃO DE DATA FINAL ***
        } elseif ($dt_final > $ultimo_dia_mes_passado) {
             $erro = 'A data final não pode ser posterior ao último dia do mês passado ('
                    . $ultimo_dia_mes_passado->format('d/m/Y') . ').';
        } else {
            // --- Preparação Comum ---
            $valor_nominal = floatval($valor_nominal_input);
            $dia_inicial_usuario = intval($dt_inicial->format('d'));

            // --- LÓGICA CONDICIONAL BASEADA NO TIPO DE ÍNDICE ---

            //============================================================
            // CASO 1: ÍNDICE DE VARIAÇÃO MENSAL (Ex: IPC-SP SGS 193)
            // Lógica: Compounding Anual (blocos 12m), Tabela Mensal
            //============================================================
            if ($tipo_calculo === 'variacao_mensal') {
                $metodo_calculo_usado = 'Variação Mensal com Aplicação Anual';

                // Formata datas para a API: primeiro dia do mês inicial até último dia do mês final
                $api_data_inicial = $dt_inicial->format('01/m/Y');
                $ultimo_dia_mes_final = cal_days_in_month(CAL_GREGORIAN, intval($dt_final->format('m')), intval($dt_final->format('Y')));
                $api_data_final = $dt_final->format($ultimo_dia_mes_final . '/m/Y');

                $url = sprintf(
                    "https://api.bcb.gov.br/dados/serie/bcdata.sgs.%d/dados?formato=json&dataInicial=%s&dataFinal=%s",
                    $codigo_sgs_selecionado, $api_data_inicial, $api_data_final
                );

                $apiResponse = buscarDadosBCB($url);

                if (!$apiResponse['success']) {
                    $erro = 'Erro ao buscar dados da API: ' . $apiResponse['message'];
                } else {
                    $dados_mensais_api = $apiResponse['data'];
                    if (empty($dados_mensais_api)) { $erro = 'A API não retornou dados para o período solicitado.'; }
                    else {
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
                        $valor_base_periodo_atual = $valor_nominal;
                        $fator_bloco_12m = 1.0;
                        $meses_no_bloco_atual = 0;
                        $fator_total_aplicado = 1.0; // Fator total efetivamente aplicado

                        $periodo = new DatePeriod(
                            (clone $dt_inicial)->modify('first day of this month'),
                            new DateInterval('P1M'),
                            (clone $dt_final)->modify('first day of next month')
                        );

                        $todos_meses_ok = true;
                        foreach ($periodo as $data_mes_atual) {
                            $mes_ano_chave_atual = $data_mes_atual->format('Y-m');
                            if (!isset($dados_por_mes[$mes_ano_chave_atual])) {
                                $erro = "Erro: Dados da API não encontrados para o mês " . $data_mes_atual->format('m/Y') . ".";
                                $todos_meses_ok = false; $tabela_resultados = array(); break;
                            }
                            $variacao_percentual = $dados_por_mes[$mes_ano_chave_atual]['variacao'];
                            $multiplicador = 1 + ($variacao_percentual / 100.0);
                            $fator_bloco_12m *= $multiplicador;
                            $meses_no_bloco_atual++;

                            $data_display = clone $data_mes_atual;
                            $ultimo_dia_mes_atual = intval($data_display->format('t'));
                            $dia_para_usar = ($dia_inicial_usuario <= $ultimo_dia_mes_atual) ? $dia_inicial_usuario : $ultimo_dia_mes_atual;
                            $data_display->setDate(intval($data_display->format('Y')), intval($data_display->format('m')), $dia_para_usar);
                            $data_display_str = $data_display->format('d/m/Y');

                            $valor_corrigido_display = $valor_base_periodo_atual;
                            $fator_acumulado_display = $fator_total_aplicado;
                            $diferenca_nominal = $valor_corrigido_display - $valor_nominal;

                            $tabela_resultados[] = array(
                                'data_display' => $data_display_str,
                                'variacao' => $variacao_percentual,
                                'fator_acumulado' => $fator_acumulado_display,
                                'valor_corrigido' => $valor_corrigido_display,
                                'diferenca' => $diferenca_nominal
                            );

                            if ($meses_no_bloco_atual == 12) {
                                $valor_base_periodo_atual *= $fator_bloco_12m;
                                $fator_total_aplicado *= $fator_bloco_12m;
                                $fator_bloco_12m = 1.0; $meses_no_bloco_atual = 0;
                            }
                        }

                        if ($todos_meses_ok && !empty($tabela_resultados)) {
                             if ($meses_no_bloco_atual > 0) {
                                $valor_corrigido_final = $valor_base_periodo_atual * $fator_bloco_12m;
                                $fator_total_final = $fator_total_aplicado * $fator_bloco_12m;
                             } else {
                                $valor_corrigido_final = $valor_base_periodo_atual;
                                $fator_total_final = $fator_total_aplicado;
                             }
                             $percentual_total = ($fator_total_final - 1) * 100.0;
                             $resultados_finais = ['fator' => $fator_total_final, 'percentual' => $percentual_total, 'valor_corrigido' => $valor_corrigido_final];
                        } elseif ($todos_meses_ok && empty($tabela_resultados)) { $erro = "Não foi possível gerar resultados."; }
                    }
                }

            //============================================================
            // CASO 2: ÍNDICE DE NÚMERO-ÍNDICE (Ex: IPCA, IGP-M)
            // Lógica: Correção Padrão (If/Ii), Tabela Mensal Progressiva
            //============================================================
            } elseif ($tipo_calculo === 'numero_indice') {
                $metodo_calculo_usado = 'Número-Índice Padrão (If / Ii)';

                // Datas de referência para API (mês anterior ao início e fim do período do usuário)
                $dt_ref_inicial = (clone $dt_inicial)->modify('first day of previous month');
                $dt_ref_final = (clone $dt_final)->modify('first day of previous month'); // Último índice necessário é do mês anterior ao final

                // Formata para API (pegando do início do mês ref inicial até fim do mês ref final)
                $api_data_inicial = $dt_ref_inicial->format('01/m/Y');
                $ultimo_dia_mes_ref_final = cal_days_in_month(CAL_GREGORIAN, intval($dt_ref_final->format('m')), intval($dt_ref_final->format('Y')));
                $api_data_final = $dt_ref_final->format($ultimo_dia_mes_ref_final . '/m/Y');

                 $url = sprintf(
                    "https://api.bcb.gov.br/dados/serie/bcdata.sgs.%d/dados?formato=json&dataInicial=%s&dataFinal=%s",
                    $codigo_sgs_selecionado, $api_data_inicial, $api_data_final
                );

                $apiResponse = buscarDadosBCB($url);

                 if (!$apiResponse['success']) {
                    $erro = 'Erro ao buscar dados da API: ' . $apiResponse['message'];
                } else {
                    $dados_indices_api = $apiResponse['data'];
                    if (empty($dados_indices_api)) { $erro = 'A API não retornou dados para o período de referência solicitado.'; }
                    else {
                         // --- Organiza dados da API por Mês/Ano ---
                         $indices_por_mes = array();
                         foreach($dados_indices_api as $item) {
                            if (!isset($item['data']) || !isset($item['valor'])) continue;
                            // A data da API para número-índice geralmente se refere ao mês anterior
                            // Vamos usar a data da API para mapear, mas sabendo sua referência
                            $dt_item = DateTime::createFromFormat('d/m/Y', $item['data']);
                             if ($dt_item) {
                                // A chave YYYY-MM deve representar o MÊS DE REFERÊNCIA do índice
                                // Ex: Se a API retorna '01/08/2020' com índice X, esse é o índice de JUL/2020.
                                // Para simplificar o mapeamento, vamos mapear pela data retornada
                                // e ajustar a busca depois. Ou melhor, mapear pelo mês anterior.
                                $dt_mes_ref = (clone $dt_item)->modify('first day of previous month');
                                $mes_ano_chave_ref = $dt_mes_ref->format('Y-m');
                                $indices_por_mes[$mes_ano_chave_ref] = ['indice' => floatval($item['valor']), 'data_api'=>$item['data']];
                             }
                        }

                        // --- Verificação e Cálculo com Número-Índice ---
                        $mes_ano_chave_ref_inicial = $dt_ref_inicial->format('Y-m');

                        // Verifica se o índice base (ref inicial) existe
                        if (!isset($indices_por_mes[$mes_ano_chave_ref_inicial])) {
                            $erro = "Erro: Índice base (" . $dt_ref_inicial->format('m/Y') . ") não encontrado na resposta da API.";
                            $todos_meses_ok = false;
                        } else {
                             $indice_base_periodo = $indices_por_mes[$mes_ano_chave_ref_inicial]['indice'];
                             if ($indice_base_periodo == 0) {
                                 $erro = "Erro: Índice base é zero, impossível calcular.";
                                 $todos_meses_ok = false;
                             } else {
                                 $todos_meses_ok = true;
                                 $indice_mes_ref_anterior = $indice_base_periodo; // Para calcular variação %
                             }
                        }

                        if($todos_meses_ok) {
                             $periodo_usuario = new DatePeriod(
                                (clone $dt_inicial)->modify('first day of this month'),
                                new DateInterval('P1M'),
                                (clone $dt_final)->modify('first day of next month')
                            );

                            foreach ($periodo_usuario as $data_mes_atual) {
                                // Mês de referência para este mês da tabela é o mês anterior
                                $dt_mes_ref_atual = (clone $data_mes_atual)->modify('first day of previous month');
                                $mes_ano_chave_ref_atual = $dt_mes_ref_atual->format('Y-m');

                                // Verifica se temos o índice para o mês de referência atual
                                if (!isset($indices_por_mes[$mes_ano_chave_ref_atual])) {
                                    $erro = "Erro: Dados do índice não encontrados para ref " . $dt_mes_ref_atual->format('m/Y') . ".";
                                    $todos_meses_ok = false; $tabela_resultados = array(); break;
                                }

                                $indice_mes_ref_atual = $indices_por_mes[$mes_ano_chave_ref_atual]['indice'];

                                // Cálculos para a linha da tabela
                                $fator_acumulado_mes = $indice_mes_ref_atual / $indice_base_periodo;
                                $valor_corrigido_mes = $valor_nominal * $fator_acumulado_mes;
                                $diferenca_nominal = $valor_corrigido_mes - $valor_nominal;

                                // Calcula variação percentual MENSAL (em relação ao mês anterior)
                                $variacao_percentual = 0;
                                if ($indice_mes_ref_anterior != 0) { // Evita divisão por zero
                                    $variacao_percentual = (($indice_mes_ref_atual / $indice_mes_ref_anterior) - 1) * 100.0;
                                }

                                // Formata data display
                                $data_display = clone $data_mes_atual;
                                $ultimo_dia_mes_atual = intval($data_display->format('t'));
                                $dia_para_usar = ($dia_inicial_usuario <= $ultimo_dia_mes_atual) ? $dia_inicial_usuario : $ultimo_dia_mes_atual;
                                $data_display->setDate(intval($data_display->format('Y')), intval($data_display->format('m')), $dia_para_usar);
                                $data_display_str = $data_display->format('d/m/Y');

                                $tabela_resultados[] = array(
                                    'data_display' => $data_display_str,
                                    'variacao' => $variacao_percentual,
                                    'fator_acumulado' => $fator_acumulado_mes,
                                    'valor_corrigido' => $valor_corrigido_mes,
                                    'diferenca' => $diferenca_nominal
                                );

                                // Atualiza o índice anterior para o próximo loop
                                $indice_mes_ref_anterior = $indice_mes_ref_atual;
                            } // Fim do loop pelos meses do usuário
                        }

                        // Calcula Resultados Finais (se tudo ok)
                        if ($todos_meses_ok && !empty($tabela_resultados)) {
                             // Pega o último resultado da tabela (já calculado corretamente)
                             $ultimo_resultado = end($tabela_resultados);
                             $resultados_finais = [
                                'fator' => $ultimo_resultado['fator_acumulado'],
                                'percentual' => ($ultimo_resultado['fator_acumulado'] - 1) * 100.0,
                                'valor_corrigido' => $ultimo_resultado['valor_corrigido']
                             ];
                        } elseif ($todos_meses_ok && empty($tabela_resultados)) { $erro = "Não foi possível gerar resultados."; }
                    }
                }

            //============================================================
            // CASO 3: TIPO DE ÍNDICE DESCONHECIDO
            //============================================================
            } else {
                 $erro = "Tipo de cálculo desconhecido para o índice selecionado.";
            }
        } // Fim das validações
    } // Fim das validações gerais
} // Fim do check POST
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculadora de Correção Monetária Flexível</title>
    <style>
        /* Mesmos estilos da versão anterior */
        body { font-family: sans-serif; line-height: 1.6; padding: 20px; max-width: 800px; margin: auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold;}
        input[type="text"], input[type="number"], select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1em;}
        button:hover { background-color: #0056b3; }
        .error { color: #dc3545; border: 1px solid #dc3545; padding: 10px; margin-bottom: 15px; border-radius: 4px; background-color: #f8d7da; }
        .results-summary { margin-top: 20px; margin-bottom: 20px; padding: 15px; border: 1px solid #ccc; border-radius: 4px; background-color: #e9ecef; }
        .results-summary h3 { margin-top: 0; }
        .results-summary p { margin: 5px 0; }
        .results-summary strong { display: inline-block; min-width: 250px; }
        .results-table { margin-top: 20px; width: 100%; border-collapse: collapse; }
        .results-table th, .results-table td { border: 1px solid #dee2e6; padding: 8px; text-align: right; }
        .results-table th { background-color: #f8f9fa; text-align: center; font-size: 0.9em;}
        .results-table td {font-size: 0.9em;}
        .results-table td:first-child { text-align: center; } /* Centraliza data */
    </style>
</head>
<body>

    <h1>Calculadora de Correção Monetária Flexível</h1>
    <p>Permite selecionar diferentes índices e datas (data final até o <strong>fim do mês passado: <?php echo $ultimo_dia_mes_passado->format('d/m/Y'); ?></strong>).</p>
    <p><strong>Atenção:</strong> O método de cálculo varia conforme o tipo de índice selecionado (veja resumo nos resultados).</p>


    <?php if ($erro): ?>
        <div class="error"><?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>

    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
        <div class="form-group">
            <label for="indice_sgs">Índice:</label>
            <select id="indice_sgs" name="indice_sgs" required>
                <option value="">-- Selecione um Índice --</option>
                <?php foreach ($indices_disponiveis as $codigo => $info): ?>
                    <option value="<?php echo $codigo; ?>" <?php echo ($indice_sgs_input == $codigo) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($info['nome']) . ' (SGS ' . $codigo . ')'; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
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
            <label for="data_final">Data Final (até <?php echo $ultimo_dia_mes_passado->format('d/m/Y'); ?>):</label>
            <input type="text" id="data_final" name="data_final" placeholder="DD/MM/AAAA" required
                   value="<?php echo htmlspecialchars($data_final_input); ?>">
        </div>
        <button type="submit">Calcular Correção</button>
    </form>

    <?php if ($resultados_finais !== null && !empty($tabela_resultados)): ?>
        <div class="results-summary">
            <h3>Resultados Finais</h3>
             <p><strong>Índice Utilizado:</strong> <?php echo htmlspecialchars($indices_disponiveis[$indice_sgs_input]['nome']); ?></p>
             <p><strong>Método de Cálculo:</strong> <?php echo htmlspecialchars($metodo_calculo_usado); ?></p>
            <p><strong>Valor Nominal Original:</strong> R$ <?php echo number_format(floatval($valor_nominal_input), 2, ',', '.'); ?></p>
            <p><strong>Período Calculado:</strong> <?php echo htmlspecialchars($data_inicial_input); ?> a <?php echo htmlspecialchars($data_final_input); ?></p>
            <p><strong>Índice de Correção Total no Período:</strong> <?php echo number_format($resultados_finais['fator'], 8, ',', '.'); ?></p>
            <p><strong>Variação Percentual Total no Período:</strong> <?php echo number_format($resultados_finais['percentual'], 6, ',', '.'); ?> %</p>
            <p><strong>Valor Corrigido na Data Final:</strong> R$ <?php echo number_format($resultados_finais['valor_corrigido'], 2, ',', '.'); ?></p>
            <p><strong>Diferença Total do Valor Nominal:</strong> R$ <?php echo number_format($resultados_finais['valor_corrigido'] - floatval($valor_nominal_input), 2, ',', '.'); ?></p>
        </div>

        <h2>Cronograma Detalhado Mês a Mês</h2>
        <table class="results-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Variação % Mês</th>
                    <?php if($tipo_calculo == 'variacao_mensal'): ?>
                        <th>Fator Aplicado (Início Mês)</th>
                        <th>Valor Corrigido (Base Anual)</th>
                    <?php else: // numero_indice ?>
                         <th>Fator Acumulado</th>
                         <th>Valor Corrigido (Fim do Mês)</th>
                    <?php endif; ?>
                    <th>Diferença do Valor Nominal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tabela_resultados as $linha): ?>
                <tr>
                    <td><?php echo htmlspecialchars($linha['data_display']); ?></td>
                    <td><?php echo number_format($linha['variacao'], 2, ',', '.'); ?> %</td>
                    <td><?php echo number_format($linha['fator_acumulado'], 8, ',', '.'); ?></td>
                    <td>R$ <?php echo number_format($linha['valor_corrigido'], 2, ',', '.'); ?></td>
                    <td>R$ <?php echo number_format($linha['diferenca'], 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</body>
</html>
