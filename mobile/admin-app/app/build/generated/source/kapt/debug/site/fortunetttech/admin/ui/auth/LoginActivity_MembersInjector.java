package site.fortunetttech.admin.ui.auth;

import dagger.MembersInjector;
import dagger.internal.DaggerGenerated;
import dagger.internal.InjectedFieldSignature;
import dagger.internal.QualifierMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.preferences.TokenPreferences;

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
public final class LoginActivity_MembersInjector implements MembersInjector<LoginActivity> {
  private final Provider<TokenPreferences> prefsProvider;

  public LoginActivity_MembersInjector(Provider<TokenPreferences> prefsProvider) {
    this.prefsProvider = prefsProvider;
  }

  public static MembersInjector<LoginActivity> create(Provider<TokenPreferences> prefsProvider) {
    return new LoginActivity_MembersInjector(prefsProvider);
  }

  @Override
  public void injectMembers(LoginActivity instance) {
    injectPrefs(instance, prefsProvider.get());
  }

  @InjectedFieldSignature("site.fortunetttech.admin.ui.auth.LoginActivity.prefs")
  public static void injectPrefs(LoginActivity instance, TokenPreferences prefs) {
    instance.prefs = prefs;
  }
}
