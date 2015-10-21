/**
 * @file
 *   MongoDB group reduction callback for LoggerController::watchdogTop().
 */

function reductor (doc, accumulator) {
  accumulator.count++;
}
