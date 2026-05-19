package site.fortunetttech.customer;

import dagger.MembersInjector;
import dagger.internal.DaggerGenerated;
import dagger.internal.InjectedFieldSignature;
import dagger.internal.QualifierMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.customer.data.preferences.TokenPreferences;

@QualifierMetadata
@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class MainActivity_MembersInjector implements MembersInjector<MainActivity> {
  private final Provider<TokenPreferences> prefsProvider;

  public MainActivity_MembersInjector(Provider<TokenPreferences> prefsProvider) {
    this.prefsProvider = prefsProvider;
  }

  public static MembersInjector<MainActivity> create(Provider<TokenPreferences> prefsProvider) {
    return new MainActivity_MembersInjector(prefsProvider);
  }

  @Override
  public void injectMembers(MainActivity instance) {
    injectPrefs(instance, prefsProvider.get());
  }

  @InjectedFieldSignature("site.fortunetttech.customer.MainActivity.prefs")
  public static void injectPrefs(MainActivity instance, TokenPreferences prefs) {
    instance.prefs = prefs;
  }
}
