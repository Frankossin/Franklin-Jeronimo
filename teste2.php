<?php
// --- Configurações e Funções Auxiliares ---
ini_set('display_errors', 1); // Mostrar erros (útil para desenvolvimento)
error_reporting(E_ALL);

// Código SGS para IPC-SP (FIPE - Variação % mensal)
define('CODIGO_SGS_IPCS', 193);

// Função para buscar dados da API do BCB usando cURL (sem alterações)
function buscarDadosBCB($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20); // Aumentado timeout para API
    curl_setopt($ch, CURLOPT_USERAGENT, 'Calculadora Correcao PHP v2');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return array('success' => false, 'message' => 'Erro de cURL: ' . $error);
    }

    if ($httpCode != 200) {
        // Tenta decodificar resposta mesmo em erro para mais detalhes
        $api_error_details = json_decode($response, true);
        $details_message = isset($api_error_details['message']) ? $api_error_details['message'] : substr($response, 0, 200);
        return array('success' => false, 'message' => 'Erro HTTP da API: ' . $httpCode . ' - ' . $details_message);
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return array('success' => false, 'message' => 'Erro ao decodificar JSON: ' . json_last_error_msg());
    }

    return array('success' => true, 'data' => $data);
}

// Função para validar data no formato DD/MM/AAAA (sem alterações)
function validarData($dateStr) {
    $d = DateTime::createFromFormat('d/m/Y', $dateStr);
    // Verifica também se o ano é razoável (evita anos como 0000)
    return $d && $d->format('d/m/Y') === $dateStr && intval($d->format('Y')) > 1900;
}

// --- Processamento do Formulário ---
$resultados = null;
$tabela_resultados = array();
$erro = '';
$valor_nominal_input = '';
$data_inicial_input = '';
$data_final_input = '';

// Definir limites de data para validação
$hoje = new DateTime();
// Clonar para não modificar $hoje
$primeiro_dia_mes_passado = (new DateTime())->modify('first day of last month')->setTime(0, 0, 0);
// Clonar e definir hora para fim do dia atual
$fim_do_dia_hoje = (new DateTime())->setTime(23, 59, 59);


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
        $dt_inicial = DateTime::createFromFormat('d/m/Y H:i:s', $data_inicial_input . ' 00:00:00');
        $dt_final = DateTime::createFromFormat('d/m/Y H:i:s', $data_final_input . ' 23:59:59');

        if ($dt_inicial >= $dt_final) {
            $erro = 'A data inicial deve ser anterior à data final.';
        // *** NOVA VALIDAÇÃO DE PERÍODO ***
        } elseif ($dt_inicial < $primeiro_dia_mes_passado || $dt_final > $fim_do_dia_hoje) {
             $erro = 'O período selecionado deve estar entre o início do mês passado ('
                    . $primeiro_dia_mes_passado->format('d/m/Y') . ') e a data atual ('
                    . $hoje->format('d/m/Y') . ').';
        } else {
            // --- Preparação para a API ---
            $valor_nominal = floatval($valor_nominal_input);
            $dia_inicial_usuario = intval($dt_inicial->format('d')); // Guarda o dia para usar na tabela

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
                             $dados_por_mes[$mes_ano_chave] = [
                                 'variacao' => floatval($item['valor']),
                                 'data_api' => $item['data'] // Guarda data original da API se precisar
                             ];
                         }
                    }

                    // --- Verificação de Completude e Cálculo Mensal ---
                    $fator_acumulado = 1.0;
                    $valor_corrigido_mes_anterior = $valor_nominal; // Inicia com o valor nominal

                    // Define o período MÊS a MÊS para iterar
                    $periodo = new DatePeriod(
                        // Inicia no primeiro dia do mês da data inicial
                        (clone $dt_inicial)->modify('first day of this month'),
                        new DateInterval('P1M'), // Intervalo de 1 mês
                        // Termina no primeiro dia do mês APÓS a data final para incluir o mês final
                        (clone $dt_final)->modify('first day of next month')
                    );

                    $todos_meses_ok = true;
                    foreach ($periodo as $data_mes_atual) {
                        $mes_ano_chave_atual = $data_mes_atual->format('Y-m');

                        // *** VERIFICAÇÃO DE COMPLETUDE ***
                        if (!isset($dados_por_mes[$mes_ano_chave_atual])) {
                            $erro = "Erro: Dados da API não encontrados para o mês " . $data_mes_atual->format('m/Y') . ". Não é possível gerar o cronograma completo.";
                            $todos_meses_ok = false;
                            $tabela_resultados = array(); // Limpa tabela parcial
                            break; // Interrompe o loop
                        }

                        // Pega a variação do mês atual
                        $variacao_percentual = $dados_por_mes[$mes_ano_chave_atual]['variacao'];
                        $multiplicador = 1 + ($variacao_percentual / 100.0);

                        // Atualiza o fator acumulado TOTAL
                        $fator_acumulado *= $multiplicador;

                        // Calcula o valor corrigido para o final deste mês
                        $valor_corrigido_este_mes = $valor_nominal * $fator_acumulado;

                        // Calcula a diferença em relação ao valor NOMINAL original
                        $diferenca_nominal = $valor_corrigido_este_mes - $valor_nominal;

                        // Formata a data de exibição com o dia do usuário
                        // Tenta definir o dia do usuário, se falhar (ex: dia 31 em Fev), usa o último dia do mês
                        $data_display = clone $data_mes_atual;
                        $ultimo_dia_mes_atual = intval($data_display->format('t')); // 't' = num dias no mês
                        if ($dia_inicial_usuario <= $ultimo_dia_mes_atual) {
                             $data_display->setDate(intval($data_display->format('Y')), intval($data_display->format('m')), $dia_inicial_usuario);
                        } else {
                             $data_display->setDate(intval($data_display->format('Y')), intval($data_display->format('m')), $ultimo_dia_mes_atual);
                        }
                        $data_display_str = $data_display->format('d/m/Y');


                        // Adiciona à tabela de resultados
                        $tabela_resultados[] = array(
                            'data_display' => $data_display_str,
                            'variacao' => $variacao_percentual,
                            'fator_acumulado' => $fator_acumulado,
                            'valor_corrigido' => $valor_corrigido_este_mes,
                            'diferenca' => $diferenca_nominal
                        );

                        // Atualiza valor para próximo loop (não usado no cálculo principal, mas poderia ser)
                        $valor_corrigido_mes_anterior = $valor_corrigido_este_mes;
                    } // Fim do loop pelos meses

                    // Se todos os meses foram processados, guarda os resultados finais
                    if ($todos_meses_ok && !empty($tabela_resultados)) {
                         $ultimo_resultado = end($tabela_resultados); // Pega o último item
                         $resultados = array(
                            'fator' => $ultimo_resultado['fator_acumulado'],
                            'percentual' => ($ultimo_resultado['fator_acumulado'] - 1) * 100.0,
                            'valor_corrigido' => $ultimo_resultado['valor_corrigido'],
                         );
                    } elseif($todos_meses_ok && empty($tabela_resultados)) {
                        // Caso estranho onde o loop não rodou mas não deu erro antes
                         $erro = "Não foi possível gerar resultados para o período.";
                    }

                } // Fim da verificação de dados da API vazios
            } // Fim da verificação de sucesso da API
        } // Fim das validações de data
    } // Fim das validações gerais
} // Fim do check POST
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculadora de Correção Monetária Detalhada (IPC-SP FIPE)</title>
    <style>
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

    <h1>Calculadora de Correção Monetária Detalhada</h1>
    <p>Utiliza o índice <strong>IPC-SP (FIPE - Variação % Mensal - SGS 193)</strong>.</p>
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

    <?php if ($resultados !== null && !empty($tabela_resultados)): ?>
        <div class="results-summary">
            <h3>Resultados Finais</h3>
            <p><strong>Valor Nominal Original:</strong> R$ <?php echo number_format(floatval($valor_nominal_input), 2, ',', '.'); ?></p>
            <p><strong>Período Calculado:</strong> <?php echo htmlspecialchars($data_inicial_input); ?> a <?php echo htmlspecialchars($data_final_input); ?></p>
            <p><strong>Índice de Correção Total no Período:</strong> <?php echo number_format($resultados['fator'], 8, ',', '.'); ?></p>
            <p><strong>Variação Percentual Total no Período:</strong> <?php echo number_format($resultados['percentual'], 6, ',', '.'); ?> %</p>
            <p><strong>Valor Corrigido na Data Final:</strong> R$ <?php echo number_format($resultados['valor_corrigido'], 2, ',', '.'); ?></p>
            <p><strong>Diferença Total do Valor Nominal:</strong> R$ <?php echo number_format($resultados['valor_corrigido'] - floatval($valor_nominal_input), 2, ',', '.'); ?></p>
        </div>

        <h2>Cronograma Detalhado Mês a Mês</h2>
        <table class="results-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Variação % Mês</th>
                    <th>Fator Acumulado</th>
                    <th>Valor Corrigido (Fim do Mês)</th>
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
