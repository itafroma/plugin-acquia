<?php

namespace Drutiny\Acquia\Audit;

use Drutiny\Annotation\Param;
use Drutiny\Sandbox\Sandbox;
use Drutiny\Audit\AbstractAnalysis;
use Drutiny\Credential\Manager;
use Drutiny\Acquia\CloudApiDrushAdaptor;
use Drutiny\Acquia\AcquiaTargetInterface;
use Drutiny\Acquia\CloudApiV2;
use Drutiny\AuditValidationException;

/**
 *
 * @Param(
 *  name = "metrics",
 *  description = "one of apache-requests, bal-cpu, bal-memory, cron-memory, db-cpu, db-disk-size, db-disk-usage, db-memory, file-disk-size, file-cpu, file-disk-usage, file-memory, http-2xx, http-3xx, http-4xx, http-5xx, mysql-slow-query-count, nginx-requests, out-of-memory, php-proc-max-reached-site, php-proc-max-reached-total, php-proc-site, php-proc-total, varnish-cache-hit-rate, varnish-requests, web-cpu, web-memory ",
 *  type = "array",
 *  default = {"web-cpu", "web-memory"}
 * )
 * @Param(
 *  name = "chart-type",
 *  description = "The type of graph, either bar or line.",
 *  type = "string",
 *  default = "bar"
 * )
 * @Param(
 *  name = "chart-height",
 *  description = "The height of the graph in pixels.",
 *  type = "integer",
 *  default = "250"
 * )
 * @Param(
 *  name = "chart-width",
 *  description = "The width of the graph in pixels.",
 *  type = "integer",
 *  default = "400"
 * )
 * @Param(
 *  name = "y-axis-label",
 *  description = "Custom label for the y-axis.",
 *  type = "string",
 *  default = "Percentage"
 * )
 * @Param(
 *  name = "stacked",
 *  description = "Determines whether or not the graph data should be stacked.",
 *  type = "boolean",
 *  default = FALSE
 * )
 * @Param(
 *  name = "maintain-aspect-ratio",
 *  description = "Maintain the original canvas aspect ratio (width / height) when resizing.",
 *  type = "boolean",
 *  default = TRUE
 * )
 */
class StackMetrics extends AbstractAnalysis {

  protected function requireCloudApiV2(Sandbox $sandbox)
  {
    return Manager::load('acquia_api_v2');
  }

  /**
   * @inheritdoc
   */
  public function gather(Sandbox $sandbox) {

    $target = $sandbox->getTarget();
    $env = ($target instanceof AcquiaTargetInterface) ? $target->getEnvironment() : CloudApiDrushAdaptor::getEnvironment($target);

    $metrics = $sandbox->getParameter('metrics');

    if (!is_array($metrics)) {
      throw new AuditValidationException("Metrics parameter must be an array. " . ucwords(gettype($metrics)) . ' given.');
    }

    $response = CloudApiV2::get('environments/' . $env['id'] . '/metrics/stackmetrics/data', [
      'filter' => implode(',', array_map(function ($metric) {
        return 'metric:' . $metric;
      }, $metrics)),
      'from' => $sandbox->getReportingPeriodStart()->format(\DateTime::ISO8601),
      'to' => $sandbox->getReportingPeriodEnd()->format(\DateTime::ISO8601),
    ]);

    $table_headers = ['Date'];
    $table_rows = [];

    foreach ($response['_embedded']['items'] as $item) {
      if (!empty($item['metadata']['host'])) {
        list($item['name'],) = explode('.', $item['metadata']['host'], 2);
      }
      if (!isset($item['name'])) {
        $item['name'] = $item['metric'];
      }
      elseif (count($metrics) > 1) {
        $item['name'] .= ':' . $item['metric'];
      }
      $table_headers[] = $item['name'];

      $idx = array_search($item['name'], $table_headers);
      foreach ($item['datapoints'] as $plot) {
        // $y == value
        // $x == epoch
        list($y, $x) = $plot;

        // Convert unix timestamp plot point to readable datetime.
        if (!isset($table_rows[$x])) {
          $table_rows[$x] = [ date('Y-m-d H:i:s', $x) ];
        }

        $table_rows[$x][$idx] = $y;
      }
    }

    // Sort the table columns by index.
    array_walk($table_rows, 'ksort');

    $sandbox->setParameter('result', $response);
    $sandbox->setParameter('env', $env);
    $sandbox->setParameter('table_headers', $table_headers);
    $sandbox->setParameter('table_rows', array_values($table_rows));

    // graph

    $graph = [
      'type' => 'line',
      'labels' => 'tr td:first-child',
      'hide-table' => TRUE,
      'height' => $sandbox->getParameter('chart-height', 250),
      'width' => $sandbox->getParameter('chart-width', 400),
      'stacked' => $sandbox->getParameter('stacked',FALSE),
      'y-axis' => $sandbox->getParameter('y-axis-label','Percentage'),
      'maintain-aspect-ratio' => $sandbox->getParameter('maintain-aspect-ratio',TRUE),
      'title' => $sandbox->getPolicy()->get('title'),
      'series' => [],
      'series-labels' => [],
      'legend' => 'bottom',
    ];

    foreach ($table_headers as $idx => $name) {
      if ($name == 'Date') {
        continue;
      }
      $nth = $idx + 1;
      $graph['series'][] = 'tr td:nth-child(' . $nth . ')';
      $graph['series-labels'][] = 'tr th:nth-child(' . $nth . ')';
    }
    $graph['series'] = implode(',', $graph['series']);
    $graph['series-labels'] = implode(',', $graph['series-labels']);

    $element = [];
    foreach ($graph as $key => $value) {
      $element[] = $key . '="' . $value . '"';
    }
    $element = '[[[' . implode(' ', $element) . ']]]';
    $sandbox->setParameter('graph', $element);
  }

}
