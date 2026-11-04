# Product photos (የምርት ፎቶዎች)

Each product can have **one display photo**. The waiter pad sells from a grid of them, the order lines and the till show a small copy,
and the production board shows one on each card. A product without a photo shows its category's picture (a line drawing of bread, cake,
a cup, a glass and so on, with its initials), never a broken image. Only the admin adds, replaces and removes photos (Admin > Catalog,
a product's page, the **Photo** card on the right).

How the server keeps and serves them is in the [server runbook](../runbook/server.md), "Product photos"; the design is
[fresh-counter.md](../design/fresh-counter.md) section 6 and ADR 0022.

## What a good photo is

- **The product alone, close up, on a plain background**: the counter, a plate, a clean tray. Fill most of the picture with it.
- **Daylight or a bright lamp**, no flash glare. The pad is read at arm's length in a busy room: a dark photo looks like an empty card.
- **Landscape, and big enough.** Every photo is shown at **4:3** (four wide, three high). JPG, PNG or WebP. The card tells you how the
  framed part will look:
  - **"Sharp on every screen"** (teal) from 1200 × 900 pixels of the photo;
  - **"This photo may look soft on big screens"** (amber) from 800 × 600: it is accepted, but a bigger one is better;
  - **"Too small for the pad"** (red) under 800 × 600: "Use this photo" stays off. Zoom out, or use a bigger photo.
  A phone's camera gives about 4000 × 3000, plenty even zoomed in.
- **No people, no faces, no hands with rings or tattoos**, no screens or papers with names or numbers. The photos are public: anyone who
  has a link can open them.
- **The real product of this shop.** A photo from the internet may not be ours to use.

The photo is cleaned in the browser before it is sent: it is turned the right way up, shrunk to at most 2400 pixels on its long side, and
saved without its location or camera details.

## Adding a photo

1. Open Admin > Catalog and the product (in the list, a product without a photo has **"Add photo"**, which opens its page at the card).
2. **Drop a photo** on the card from your computer's folder, or tap **"browse your files"** (on a tablet: the camera or the gallery).
3. **Frame it.** The photo sits in the fixed 4:3 frame:
   - drag it to move it (with a mouse, a finger or the arrow keys);
   - pinch, or use **Zoom** (1× to 3×), to make it bigger; the lines split the frame in thirds, to place the product well;
   - **"How it will look"** shows the product as the pad card (with its stock chip) and as the small picture of an order line, as you
     move it.
4. Press **"Use this photo"**. The card shows "Uploading and making sizes" with how far it is. The server makes the three sizes (a small
   one for lists, one for the pad cards, a large one for this page), then the card shows the photo and **"Added by <name> · <date> · W ×
   H"**.

The pads show the new photo the next time they look at the menu (within seconds when they are online). Until the upload is done they keep
the old one. The page needs the connection: an upload is never kept for later, it is sent or it fails and says why under the card.

"Choose another" picks a different file; "Cancel" leaves the product as it was.

## Replacing and removing

- **Replace** opens your files again, and you frame the new one the same way. The old photo stops being shown everywhere at once.
- **Remove** asks first ("Remove the photo"). The pads show the category picture again.

Neither deletes anything for good: the old photo is kept for 30 days and stays in the backups of the nights before (the runbook's "The
prune"), and the audit log (Admin > Audit log) shows who changed which product's photo, and when.

## When the card refuses a photo

The reason is in a red box under the card, in the page's language. The usual ones:

| The card says | What to do |
|---|---|
| This kind of picture is not accepted | Use a JPG, PNG or WebP (a phone's photo is usually a JPG). A GIF, a PDF or an iPhone's HEIC file is not: export it as JPG first. |
| No picture was sent, or this file is not a picture | The file is damaged or not a photo. Take or export it again. |
| This photo is too big to upload | Over 20 MB. Choose a smaller file, or take the photo at a lower size. |
| This photo is too small for the 4:3 frame | The framed part is under 800 × 600. Zoom out, or use a bigger photo. |
| Product photos cannot be prepared on this server right now | The server cannot make photos (its image library). Tell whoever looks after the server. |

A refusal changes nothing: the product keeps the photo it had.

## Demo photos are for the demo branch only

The **Demo Bakery** branch comes with free-licence stock photos of its products, so its pads look as they will in use (the card says "Demo
photo"). They are never put on a real shop's branch, and they are not photos of this shop's products. Replace them with your own before a
real branch goes live: a shop's own branch starts with no photos, and each product shows its category picture until you add one.
