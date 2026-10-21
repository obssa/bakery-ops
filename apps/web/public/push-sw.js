// Imported by the shell cache worker (scripts/build-sw.mjs): shows a web push from the API (PushDriver sends
// {title, body, url}, where url is the deep link path of the inbox line, e.g. /production?request=12) and opens that
// screen when the notification is tapped. Plain JavaScript, no build step.
self.addEventListener("push", (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { title: event.data ? event.data.text() : "" };
  }
  event.waitUntil(
    self.registration.showNotification(data.title || "Bakery Ops", {
      body: data.body || "",
      data: { url: data.url || null },
    }),
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const path = event.notification.data && event.notification.data.url;
  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((windows) => {
      const open = windows.map((w) => /\/app\/(en|am)\//.exec(w.url)).find(Boolean);
      const locale = open ? open[1] : "en";
      const target = path ? `/app/${locale}${path.replace(/^([^?]*?)\/?(\?|$)/, "$1/$2")}` : `/app/${locale}/`;
      return self.clients.openWindow(target);
    }),
  );
});
