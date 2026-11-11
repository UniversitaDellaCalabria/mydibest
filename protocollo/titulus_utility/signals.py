from django.db.models.signals import *
from django.dispatch import receiver
from django.utils import timezone

from .models import *


@receiver(pre_save, sender=CredentialWSProtocollo)
@receiver(pre_save, sender=ConfigurationWSProtocollo)
def disable_others_active_protocol_configurations(sender, instance, **kwargs):
    """
    If a credential or a configuration is enabled
    all the others must be disabled (only one active!)
    """

    # Turn off a signal handler when loading fixture data
    # loaddata saves objects with raw=True
    if kwargs.get('raw'):
        return
    
    if instance.is_active:
        sender.objects.filter(
            content_type=instance.content_type,
            object_id=instance.object_id
        ).exclude(
            pk=instance.pk
        ).update(
            is_active=False,
            modified=timezone.now()
        )