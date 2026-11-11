import logging
import os
from titulus_utility import conf as titulus_settings
logger = logging.getLogger(__name__)


class SOAPPayloadMixin:
    """Mixin per classi che devono codificare e preparare file da inviare via SOAP."""

    def _get_payload_dict(self, content, nome, descrizione):
        return {
            'content': content,
            'filename': nome,
            'description': descrizione
        }

    def encode_file_base64(self, client, fopen, nome, descrizione, ns_key="ns1"):
        """Codifica un file in Base64 usando i tipi complessi di Zeep."""
        ext = os.path.splitext(nome)[1]
        if not ext:
            raise Exception(f"'{nome}' deve avere l'estensione per evitare errori lato Titulus.")


        namespaces = titulus_settings.PROTOCOL_NAMESPACES
        ns = namespaces[ns_key]

        try:
            content_type = client.get_type(f'{ns}base64Binary')
            content = content_type(fopen.read())
            # I campi dell'AttachmentBean sono di tipo soapenc:string, cioè un
            # complexType con attributi (id, href). Passando una str "nuda", zeep
            # esegue `name in value` (match di sottostringa!) e poi `value[name]`:
            # se nome/descrizione contengono "id" o "href" (es. "candidatura.pdf")
            # si ottiene "TypeError: string indices must be integers".
            # Wrappando i valori nel tipo soapenc:string il problema non si pone.
            string_type = client.get_type(f'{ns}string')
            return self._get_payload_dict(content,
                                          string_type(nome),
                                          string_type(descrizione))
        except Exception as e:
            logger.exception(f"Errore durante la codifica base64 di {nome}: {e}")
            raise