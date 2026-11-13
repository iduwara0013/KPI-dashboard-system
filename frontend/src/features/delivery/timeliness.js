export function weightedTimeliness(rows, weightOf = row => row.weight) {
  let assessed = 0, scheduled = 0, points = 0, estimated = 0;
  for (const row of rows) {
    const timing = row.timeliness;
    if (!timing) continue;
    const weight = Number(weightOf(row) || 0);
    const finalWeight = weight * Number(timing.assessed_weight || 0);
    const dueWeight = weight * Number(timing.scheduled_weight || 0);
    assessed += finalWeight; scheduled += dueWeight;
    points += finalWeight * Number(timing.score || 0);
    estimated += dueWeight * Number(timing.estimate || 0);
  }
  return { score: assessed ? points / assessed : null, estimate: scheduled ? estimated / scheduled : null,
    assessed_weight: assessed, scheduled_weight: scheduled, coverage: scheduled ? assessed / scheduled * 100 : 0 };
}
