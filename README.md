# DomainNameAPI Registrar Plugin for ClientExec

## 📦 Download — always use Releases!

⬇️ **Get the latest tested version here: https://github.com/domainreseller/clientexec-dna/releases/latest**

> ⚠️ Do **not** use the green **Code → Download ZIP** button — that downloads the raw development branch. Release packages are versioned, tested and production-ready.

## 🔑 API Credentials — Username/Password or Reseller ID/API Key?

Both are supported — enter them into the same two module fields; the module detects which API to use automatically:

| You have | "Username" field | "Password" field | API used |
|---|---|---|---|
| **New panel credentials** (recommended) | Reseller ID — UUID like `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` | API Key | REST |
| **Legacy credentials** | API username | API password | SOAP |

> 💡 Find your **Reseller ID** and **API Key** in your DomainNameAPI panel under **API Settings**.
> ⚠️ These are **API credentials** — your panel login e-mail and password will **not** work here.

No extra configuration is needed — if the username field contains a UUID the module uses the modern REST API, otherwise classic SOAP.
