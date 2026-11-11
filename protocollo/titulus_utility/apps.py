from django.apps import AppConfig


class TitulusUtility(AppConfig):
    name = 'titulus_utility'
    verbose_name = 'Titulus Django utility'

    def ready(self):
        # Signals
        import titulus_utility.signals