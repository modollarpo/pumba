module.exports = async function (context, myTimer) {
  const url = process.env.CRON_URL;
  if (!url) {
    context.log('CRON_URL is not set; skipping cron');
    return;
  }
  try {
    const res = await fetch(url, {
      method: 'GET',
      headers: { 'x-functions-cron': '1' },
      signal: AbortSignal.timeout(58000)
    });
    context.log('cronjob status=' + res.status);
  } catch (err) {
    context.log('cronjob error: ' + err.message);
  }
};